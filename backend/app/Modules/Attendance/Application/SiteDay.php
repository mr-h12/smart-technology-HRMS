<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\ManagerWindow;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The site manager's day at one of their sites (design Part 2): the site's workers on that date, then the manager's engineers,
 * who float between the manager's sites and so show the site they were stored at. No pay figures: transport, outside_* and
 * advance_amount stay out of the read, and the save reads only a site, two times and a note per row (Review Focus 5).
 */
final class SiteDay
{
    /** The save body; nothing else in a row is read. */
    public const FORMAT = [
        'paper_decision_date' => ['missing'],
        'rows.*.paper_decision_date' => ['missing'],
        'site_id' => ['required', 'uuid'],
        'date' => ['required', 'date_format:Y-m-d'],
        'rows' => ['required', 'list', 'min:1', 'max:'.AttendanceDays::BULK_MAX_ROWS],
        'rows.*.employee_id' => ['required', 'uuid', 'distinct'],
        'rows.*.clear' => ['sometimes', 'accepted'],
        'rows.*.version' => ['nullable', 'integer', 'min:1', 'required_with:rows.*.clear'],
        'rows.*.site_id' => ['present_unless:rows.*.clear,true,1,yes,on', 'nullable', 'uuid'],
        'rows.*.in_time' => ['nullable', 'date_format:H:i'],
        'rows.*.out_time' => ['nullable', 'date_format:H:i'],
        'rows.*.advance_request' => [...AttendanceDays::FORMAT['advance_amount'], 'numeric', 'gt:0'],
        'rows.*.note' => ['nullable', 'string', 'max:500'],
    ];

    /** The leave day-types a site manager may store (P11); annual, casual and unpaid go through a leave request. */
    public const MANAGER_LEAVE = [LeaveCode::Absent, LeaveCode::Sick, LeaveCode::Rest, LeaveCode::Other];

    private const DAY = ['state', 'version', 'site_id', 'leave_code', 'in_time', 'out_time', 'note'];

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly SiteService $sites,
        private readonly AttendanceDays $attendance,
        private readonly BusinessRules $rules,
        private readonly DayCrew $crew,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
        private readonly AttendanceRequests $requests,
    ) {}

    /**
     * Each row carries the person's scheduled shift (their pay-row override, else the field shift setting) for the bulk fills,
     * and whether the site manager may change the day now. `why_not`:
     * - locked: the period is locked;
     * - window: the date is past the yesterday window (`departure_only` rows are the exception: an open day of theirs here, where only
     *   the departure can still be entered, owner 2026-11-05);
     * - hr_day: HR recorded it, or an approved request wrote it; only HR changes it (owner, 2026-11-04);
     * - open_day: an earlier field day has an arrival and no departure. `open_date` names it, and `open_site_id` is where the site
     *   manager can complete it; null when they cannot (HR's day, or not theirs), and then HR completes it.
     * `previous` is the day «زي امبارح» copies (P10). `leave_day_types` are the leave day-types a site manager may store (P11), by code.
     *
     * @return array{site: array{id: string, name_ar: string, name_en: ?string}, date: string, today: string, leave_day_types: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function read(string $siteId, string $date, string $managerId): array
    {
        [$site, $listed, $days] = $this->load($siteId, $date, $managerId);
        $settings = $this->rules->all();
        $window = $this->windowAllows($date, $settings);
        $locked = [];
        $open = $this->openDays(array_map(fn (array $l) => $l[0], $listed), $date, $managerId);
        $previous = $this->previousDays(array_map(fn (array $l) => (string) $l[0]['id'], $listed), $date, $managerId);

        $requests = $this->requests->forSite($siteId, $date, $managerId, array_column(array_column($listed, 0), 'id'));

        return [
            'site' => ['id' => (string) $site->getKey(), 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en],
            'date' => $date,
            'today' => EffectiveDays::today(),
            'leave_day_types' => $this->managerLeaveDayTypes(),
            'rows' => array_map(function (array $l) use ($days, $date, $siteId, $settings, $window, $open, $previous, $requests, &$locked): array {
                [$e, $section] = $l;
                $day = $days[$e['id']][$date];
                $type = (string) $day['type'];
                $locked[$type] ??= PeriodGuard::isLocked(Period::containing(EmployeeType::from($type), $date));
                $departureOnly = ! $window && self::isOpenHere($day, $siteId, EmployeeType::from($type));
                $whyNot = match (true) {
                    $locked[$type] => 'locked',
                    ! $window && ! $departureOnly => 'window',
                    self::hrDay($day) => 'hr_day',
                    isset($open[$e['id']]) => 'open_day',
                    default => null,
                };

                return [
                    'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'type' => $type],
                    'section' => $section,
                    'shift' => EffectiveDays::fieldShift($e, $date, $settings),
                    'day' => array_intersect_key($day, array_flip(self::DAY)),
                    'can_edit' => $whyNot === null,
                    'why_not' => $whyNot,
                    'departure_only' => $whyNot === null && $departureOnly,
                    'open_date' => $open[$e['id']]['date'] ?? null,
                    'open_site_id' => $open[$e['id']]['site_id'] ?? null,
                    'requests' => ['overtime' => null, 'advance' => null, ...($requests[$e['id']] ?? [])],
                    'previous' => $previous[$e['id']] ?? null,
                ];
            }, $listed),
        ];
    }

    /**
     * The site manager's save, all or nothing (owner, 2026-11-04). A row outside the day's list is 404. Every refused row is one detail
     * of the 422, with its stable code:
     * - past the yesterday window, only an open day's departure here (`departure_only`; any other row `outside_manager_window`), until
     *   the period is locked; each such save is a past-day edit, and HR hears of it at once (owner, 2026-11-05);
     * - a day HR recorded (or an approved request wrote) is HR's to change or clear: `hr_day`;
     * - an earlier open field day of the same person must be completed first: `open_earlier_day`;
     * - a present day needs its arrival (`arrival_required`); the site is this one or a leave day-type (`site_not_for_manager`); a clear
     *   removes only the site manager's own day here or on one of their leave day-types (`clear_not_allowed`).
     * The checks and the write run with the people's rows locked, so a crew change of the same person cannot slip between them.
     * AttendanceDays::bulk writes with source `manager` and the manager's reach. Late minutes are raw (calculations §2b) and are
     * recomputed only when the arrival changes; overtime is set to 0 (held until HR approves it) only when a time changes, so an
     * unchanged time keeps what is stored.
     *
     * @param  array{site_id: string, date: string, rows: list<array<string, mixed>>}  $body  validated against FORMAT
     * @return array<string, mixed> the day after the save, as read() returns it
     */
    public function save(array $body, string $managerId): array
    {
        ['site_id' => $siteId, 'date' => $date, 'rows' => $rows] = $body;
        $ids = array_map(fn (array $r) => (string) $r['employee_id'], $rows);
        $this->crew->locked($ids, function () use ($siteId, $date, $rows, $managerId): void {
            [$site, $listed, $days] = $this->load($siteId, $date, $managerId);
            $byId = [];
            foreach ($listed as [$e]) {
                $byId[$e['id']] = $e;
            }
            $settings = $this->rules->all();
            $window = $this->windowAllows($date, $settings);
            $allowedLeave = array_values($this->managerLeaveDayTypes());
            $open = $this->openDays(array_values(array_filter(array_map(fn (array $r) => $byId[(string) $r['employee_id']] ?? null, $rows))), $date, $managerId);

            $refused = [];
            $out = [];
            $overtime = [];
            $advances = [];
            foreach ($rows as $i => $row) {
                $e = $byId[(string) $row['employee_id']] ?? throw new ModelNotFoundException;
                $stored = $days[$e['id']][$date];
                $clear = isset($row['clear']);
                $target = $clear ? null : ($row['site_id'] === null ? null : (string) $row['site_id']);
                $type = EmployeeType::from((string) $stored['type']);
                $reason = match (true) {
                    ! $window && ! self::isOpenHere($stored, $siteId, $type) => [AttendanceRefusal::OutsideManagerWindow, ['until' => $settings['manager_yesterday_until']]],
                    ! $window && ! self::onlyDeparture($row, $stored, $siteId) => [AttendanceRefusal::DepartureOnly, []],
                    self::hrDay($stored) => [AttendanceRefusal::HrDay, []],
                    isset($open[$e['id']]) => [AttendanceRefusal::OpenEarlierDay, ['date' => $open[$e['id']]['date']]],
                    $clear && ($stored['state'] !== 'stored' || ($stored['site_id'] !== $siteId && ! in_array($stored['site_id'], $allowedLeave, true))) => [AttendanceRefusal::ClearNotAllowed, []],
                    ! $clear && $target !== $siteId && ! in_array($target, $allowedLeave, true) => [AttendanceRefusal::SiteNotForManager, []],
                    ! $clear && $target === $siteId && ($row['in_time'] ?? null) === null => [AttendanceRefusal::ArrivalRequired, []],
                    default => null,
                };
                if ($reason !== null) {
                    $refused[] = [$reason[0], $reason[1], $this->detail($i, $reason[0], $reason[1])];

                    continue;
                }
                $work = $target === $siteId;
                $values = $this->values($row, $work);
                $in = $values['in_time'] ?? null;
                $departure = $values['out_time'] ?? null;
                $timesChanged = $stored['site_id'] !== $target || $stored['in_time'] !== $in || $stored['out_time'] !== $departure;
                if ($timesChanged && in_array($type, [EmployeeType::Engineer, EmployeeType::DayLaborer], true)) {
                    $minutes = 0;
                    if ($in !== null && $departure !== null) {
                        $shift = EffectiveDays::fieldShift($e, $date, $settings);
                        $minutes = DayRules::workerMinutes($shift['start'], $shift['end'], $in, $departure)['ot'];
                    }
                    $overtime[$e['id']] = [$in, $departure, $minutes];
                }
                if (array_key_exists('advance_request', $row)) {
                    if (! $work || ! $window) {
                        throw ValidationException::withMessages(["rows.{$i}.advance_request" => __('validation.prohibited', ['attribute' => 'advance_request'])]);
                    }
                    $advances[$e['id']] = [$row['advance_request'], false];
                    $values['advance_request_changed'] = true;
                } elseif ($timesChanged && $stored['site_id'] !== $target) {
                    $advances[$e['id']] = [null, true];
                }
                $out[] = $clear
                    ? ['employee_id' => $e['id'], 'version' => $row['version'], 'clear' => true]
                    : ['employee_id' => $e['id'], 'version' => $row['version'] ?? null, 'site_id' => $target, ...$values];
            }
            if ($refused !== []) {
                throw AttendanceRefused::because($refused[0][0], $refused[0][1], array_column($refused, 2));
            }
            $this->attendance->bulk($date, $out, $managerId, 'manager');
            foreach ($overtime as $id => [$in, $departure, $minutes]) {
                $this->requests->overtime((string) $id, $date, $siteId, $managerId, $in, $departure, $minutes);
            }
            foreach ($advances as $id => [$amount, $withdraw]) {
                $this->requests->advance((string) $id, $date, $siteId, $managerId, $amount, $withdraw);
            }
            if (! $window) {
                $this->tellHr($site, $date, array_map(fn (array $o) => $byId[$o['employee_id']], $out));
            }
        });

        return $this->read($siteId, $date, $managerId);
    }

    /**
     * A departure entered after the yesterday window is an edit to a past day: each holder of attendance.edit.all hears of it at once,
     * and can correct it (owner, 2026-11-05). One notification per day written.
     *
     * @param  list<array<string, mixed>>  $employees
     */
    private function tellHr(Site $site, string $date, array $employees): void
    {
        $settings = $this->rules->all();
        if (! ManagerWindow::isPastDayEdit($date, EffectiveDays::today(), now('Africa/Cairo')->format('H:i'), $settings['manager_yesterday_until'])) {
            return;
        }
        $hr = $this->holders->userIds('attendance.edit.all');
        $written = AttendanceDay::where('date', $date)->whereIn('employee_id', array_column($employees, 'id'))->get(['id', 'employee_id', 'out_time'])->keyBy('employee_id');
        foreach ($employees as $e) {
            $day = $written[$e['id']] ?? null;
            $type = Employment::typeOn($e, $date);
            if ($day === null || $type === null) {
                continue;
            }
            $data = [
                'employee_id' => $e['id'], 'date' => $date, 'out_time' => substr((string) $day->out_time, 0, 5),
                'site_name_ar' => (string) $site->name_ar, 'site_name_en' => $site->name_en,
                'group' => $type->value, 'period_start' => Period::containing($type, $date)->start,
            ];
            foreach ($hr as $id) {
                $this->notifier->notifyOnce($id, 'attendance_past_edit', EffectiveDays::today(), $data, (string) $day->id);
            }
        }
    }

    /**
     * The site manager's own open field day at this site: a work day here with an arrival and no departure. Past the window, only its
     * departure can still be entered.
     *
     * @param  array<string, mixed>  $day
     */
    private static function isOpenHere(array $day, string $siteId, EmployeeType $type): bool
    {
        return $type !== EmployeeType::Office && $day['state'] === 'stored' && $day['source'] === 'manager' && $day['site_id'] === $siteId
            && $day['leave_code'] === null && $day['in_time'] !== null && $day['out_time'] === null;
    }

    /**
     * The row only adds the departure: same site, same arrival, a departure, and no note change.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stored
     */
    private static function onlyDeparture(array $row, array $stored, string $siteId): bool
    {
        return ! isset($row['clear']) && ($row['site_id'] ?? null) === $siteId && ($row['in_time'] ?? null) === $stored['in_time']
            && ($row['out_time'] ?? null) !== null && (! array_key_exists('note', $row) || $row['note'] === $stored['note']);
    }

    /** @return array<string, string> leave code => the active leave day-type, for those a site manager may store */
    private function managerLeaveDayTypes(): array
    {
        $sites = Site::where(['kind' => SiteKind::Leave->value, 'active' => true])
            ->whereIn('leave_code', array_map(fn (LeaveCode $c) => $c->value, self::MANAGER_LEAVE))->get(['id', 'leave_code']);

        return $sites->mapWithKeys(fn (Site $s) => [(string) $s->leave_code => (string) $s->id])->all();
    }

    /**
     * A day HR recorded, imported or materialized, or that an approved leave request wrote (sick included): only HR changes it
     * (owner, 2026-11-04). The site manager's own days have source `manager`.
     *
     * @param  array<string, mixed>  $day
     */
    private static function hrDay(array $day): bool
    {
        return $day['state'] === 'stored' && $day['source'] !== 'manager';
    }

    /**
     * «زي امبارح» (P10): per employee, the latest day in the 7 before $date at one of this site manager's sites or on a leave day-type,
     * skipping Friday, Saturday and official holidays. A day at another manager's site never shows (PR #24 review: a worker HR moved
     * here today would show their week at the other site), and a day of request-only leave (annual, casual, unpaid) gives nothing to
     * copy: those days need a request.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{date: string, site_id: ?string, leave_code: ?string, in_time: ?string, out_time: ?string}>
     */
    private function previousDays(array $employeeIds, string $date, string $managerId): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $from = (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d');
        $off = [LeaveCode::Friday->value, LeaveCode::Saturday->value, LeaveCode::OfficialHoliday->value];
        $mine = $this->sites->idsManagedBy($managerId);
        $rows = DB::table('attendance_days as d')->join('sites as s', 's.id', '=', 'd.site_id')
            ->whereNull('d.deleted_at')->whereIn('d.employee_id', $employeeIds)->where('d.date', '>=', $from)->where('d.date', '<', $date)
            ->where(fn ($q) => $q->whereIn('d.site_id', $mine)->orWhere(fn ($l) => $l->where('s.kind', SiteKind::Leave->value)->whereNotIn('s.leave_code', $off)))
            ->selectRaw('distinct on (d.employee_id) d.employee_id, d.date, d.site_id, s.leave_code, d.in_time, d.out_time')
            ->orderBy('d.employee_id')->orderByDesc('d.date')->get();
        $requestOnly = [LeaveCode::Annual->value, LeaveCode::Casual->value, LeaveCode::Unpaid->value];
        $hhmm = fn (?string $t) => $t === null ? null : substr($t, 0, 5);
        $out = [];
        foreach ($rows as $r) {
            if (in_array($r->leave_code, $requestOnly, true)) {
                continue;
            }
            $out[(string) $r->employee_id] = [
                'date' => substr((string) $r->date, 0, 10), 'site_id' => (string) $r->site_id,
                'leave_code' => $r->leave_code, 'in_time' => $hhmm($r->in_time), 'out_time' => $hhmm($r->out_time),
            ];
        }

        return $out;
    }

    /**
     * Per employee, the latest field day before $date with an arrival and no departure (any source), in a period that is still open:
     * it is completed before a later day is saved (owner, 2026-11-04). Office days never count, and a locked period's day never blocks
     * (PR #22 review B2). `site_id` is where this site manager can complete it, or null: a day HR recorded, or one at a site that is
     * not theirs or where the person was not in their reach that day (B3), is HR's to complete.
     *
     * @param  list<array<string, mixed>>  $employees
     * @return array<string, array{date: string, site_id: ?string}>
     */
    private function openDays(array $employees, string $date, string $managerId): array
    {
        if ($employees === []) {
            return [];
        }
        $byId = array_column($employees, null, 'id');
        $candidates = AttendanceDay::whereIn('employee_id', array_keys($byId))->where('date', '<', $date)
            ->whereNotNull('in_time')->whereNull('out_time')->orderByDesc('date')->get(['employee_id', 'date', 'site_id', 'source']);
        $mine = $this->sites->idsManagedBy($managerId);
        $locked = [];
        $reach = [];
        $open = [];
        foreach ($candidates as $d) {
            $id = (string) $d->employee_id;
            $on = (string) $d->date;
            $type = Employment::typeOn($byId[$id], $on);
            if (isset($open[$id]) || $type === null || $type === EmployeeType::Office) {
                continue;
            }
            $period = Period::containing($type, $on);
            if ($locked[$type->value.$period->start] ??= PeriodGuard::isLocked($period)) {
                continue;
            }
            $site = $d->site_id === null ? null : (string) $d->site_id;
            $editable = $d->source === 'manager' && $site !== null && in_array($site, $mine, true)
                && in_array($id, $reach[$site.$on] ??= $this->reachOn($site, $on, $managerId), true);
            $open[$id] = ['date' => $on, 'site_id' => $editable ? $site : null];
        }

        return $open;
    }

    /** @return list<string> who is on $siteId's list on $date: its workers, and this manager's engineers */
    private function reachOn(string $siteId, string $date, string $managerId): array
    {
        return array_map(fn (array $e) => (string) $e['id'], [...$this->roster->onSite($siteId, $date, $managerId), ...$this->roster->engineersOf($managerId, $date)]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> what the row stores besides the site. A note is written only when the row sends one, so HR's note
     *                              stays (P28 keeps an unsent field); a leave day sends no times. The minutes come from AttendanceDays.
     */
    private function values(array $row, bool $work): array
    {
        $note = array_key_exists('note', $row) ? ['note' => $row['note']] : [];

        return $work ? ['in_time' => $row['in_time'] ?? null, 'out_time' => $row['out_time'] ?? null, ...$note] : $note;
    }

    /** @param  array<string, string>  $settings */
    private function windowAllows(string $date, array $settings): bool
    {
        return ManagerWindow::allows($date, EffectiveDays::today(), now('Africa/Cairo')->format('H:i'), $settings['manager_yesterday_until']);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{field: string, code: string, message: string}
     */
    private function detail(int $i, AttendanceRefusal $reason, array $params = []): array
    {
        return ['field' => "rows.{$i}", 'code' => $reason->value, 'message' => __('attendance.refusal.'.$reason->value, $params)];
    }

    /** @return array{0: Site, 1: list<array{0: array<string, mixed>, 1: string}>, 2: array<string, array<string, array<string, mixed>>>} */
    private function load(string $siteId, string $date, string $managerId): array
    {
        if (! in_array($siteId, $this->sites->idsManagedBy($managerId), true)) {
            throw new ModelNotFoundException;
        }
        if ($date > EffectiveDays::today()) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        $site = Site::findOrFail($siteId);

        $listed = [
            ...array_map(fn (array $e) => [$e, 'workers'], $this->roster->onSite($siteId, $date, $managerId)),
            ...array_map(fn (array $e) => [$e, 'engineers'], $this->roster->engineersOf($managerId, $date)),
        ];
        // Both lists hold only those employed on $date, so each has a type on it; one period read per type present.
        $byType = [];
        foreach ($listed as [$e]) {
            $byType[Employment::typeOn($e, $date)?->value][] = $e;
        }
        $days = [];
        foreach ($byType as $type => $employees) {
            $days += $this->days->forPeriod(Period::containing(EmployeeType::from((string) $type), $date), $employees);
        }

        return [$site, $listed, $days];
    }
}
