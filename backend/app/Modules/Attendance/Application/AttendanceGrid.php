<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The period header, the HR grid, the day sheet and the missing days of one group's period; $ownerId (own scope) narrows each to that manager's employees. */
final class AttendanceGrid
{
    /** The last site looks this many days back from the sheet date (P31). */
    public const LAST_SITE_DAYS = 7;

    private const FIELD = [EmployeeType::Engineer, EmployeeType::PermanentWorker, EmployeeType::DayLaborer];

    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days, private readonly BusinessRules $rules, private readonly DayCrew $crew, private readonly AuthorizeAction $authorize, private readonly Signoffs $signoffs) {}

    /**
     * `missing` follows the grid: archived employees count only when $withArchived, asked by $by, is honoured (honoursWithArchived).
     *
     * @return array{group: string, start: string, end: string, prev_start: string, next_start: ?string, is_locked: bool, lock: ?array<string, mixed>, missing: int}
     */
    public function period(EmployeeType $g, ?string $start, ?string $date = null, ?string $ownerId = null, bool $withArchived = false, ?Authenticatable $by = null): array
    {
        $p = $this->periodAt($g, $start, $date);
        $next = $p->next();

        return [
            'group' => $g->value,
            'start' => $p->start,
            'end' => $p->end,
            'prev_start' => $p->previous()->start,
            'next_start' => $next->start > EffectiveDays::today() ? null : $next->start,
            'is_locked' => PeriodGuard::isLocked($p),
            'lock' => $this->lock($p),
            'missing' => count($this->missingDays($p, $ownerId, $this->honoursWithArchived($withArchived, $by))),
        ];
    }

    /**
     * $siteId keeps the employees with a live stored day at that site in the period (owner: who worked at the site in the period),
     * plus, for All and office, the office staff at that site on the period's last day (owner, 2026-11-08).
     * A null $g is All (owner, 2026-11-08, office added to 2026-11-05's field types): every type over the engineers' 26th→25th window.
     * $search keeps the employees whose name contains it or whose code is it, as the employees list does (owner, 2026-11-10).
     * $withArchived, asked by $by and honoured as honoursWithArchived says, brings archived employees back as rows, each flagged `archived`.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function grid(?EmployeeType $g, ?string $start, int $perPage, int $page, ?string $date = null, ?string $ownerId = null, ?string $siteId = null, ?string $search = null, bool $withArchived = false, ?Authenticatable $by = null): array
    {
        $p = $this->periodAt($g ?? EmployeeType::Engineer, $start, $date);
        $employees = $this->roster->withArchived($this->honoursWithArchived($withArchived, $by));
        $ids = $siteId === null ? null : [
            ...AttendanceDay::where('site_id', $siteId)->whereBetween('date', [$p->start, $p->end])->distinct()->pluck('employee_id')->map(fn ($id) => (string) $id)->all(),
            ...($g === null || $g === EmployeeType::Office ? $employees->officeIdsAt($siteId, $p->end) : []),
        ];
        $roster = $employees->ofType($g === null ? EmployeeType::cases() : [$g], $p->start, $p->end, $perPage, $page, $ownerId, $ids, $search);
        $days = $g === null ? $this->daysOfEveryType($p, $roster['data']) : $this->days->forPeriod($p, $roster['data']);

        $signoffs = $ownerId === null ? $this->signoffs->overlapping(array_column($roster['data'], 'id'), $p->start, $p->end, $g) : [];

        return [
            'data' => array_map(fn (array $e) => [
                'signoffs' => array_map(Signoffs::summary(...), $signoffs[$e['id']] ?? []),
                'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'archived' => $e['archived']],
                'days' => array_values(array_map(
                    fn (array $d) => ['date' => $d['date'], 'state' => $d['state'], 'source' => $d['source'], 'site_id' => $d['site_id'], 'leave_code' => $d['leave_code']],
                    $days[$e['id']],
                )),
            ], $roster['data']),
            'pagination' => $roster['pagination'],
        ];
    }

    /**
     * The lock dialog's list: archived employees employed in the period too, flagged, since the lock still needs their days (owner, 2026-11-11).
     *
     * @return array{data: list<array{employee_id: string, code: int, name_ar: string, name_en: ?string, archived: bool, date: string}>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function missing(EmployeeType $g, ?string $start, int $perPage, ?string $date = null, ?string $ownerId = null): array
    {
        $all = $this->missingDays($this->periodAt($g, $start, $date), $ownerId, true);
        $perPage = min(max($perPage, 1), 100);
        $page = Paginator::resolveCurrentPage();
        $total = count($all);
        $pages = max(1, (int) ceil($total / $perPage));

        return [
            'data' => array_slice($all, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $pages,
                'has_next_page' => $page < $pages, 'has_previous_page' => $page > 1,
            ],
        ];
    }

    /**
     * One employee's days in their own payroll period (owner, 2026-11-10): the type on $date (default today in Cairo), $date first clamped
     * to the employment (hire date and first type, termination date). `prev_date` / `next_date` are the days just outside the period's employed
     * days, so the arrows cross a type change without skipping a day; null outside the employment, and `next_date` after today (the grid header's
     * rule). `summary` counts the days up to today; future and not-employed days count nowhere. Day counts only, never minutes or money.
     *
     * @return array{period: array{group: string, start: string, end: string, prev_date: ?string, next_date: ?string, is_locked: bool}, fields: list<string>, days: list<array<string, mixed>>, summary: array{worked: int, absent: int, leave: int, off: int, missing: int}}
     */
    public function employeePeriod(string $employeeId, ?string $date = null): array
    {
        $e = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $d = $date ?? EffectiveDays::today();
        $d = max(min($d, $e['termination_date'] ?? $d), $e['hire_date'] ?? '', $e['types'][0]['effective_from'] ?? '');
        $type = Employment::typeOn($e, $d) ?? throw new ModelNotFoundException;   // no type at all, or terminated before the first one
        $p = Period::containing($type, $d);
        $days = $this->days->forPeriod($p, [$e])[$employeeId];
        $employed = array_keys(array_filter($days, fn (array $x) => $x['state'] !== 'not_employed'));
        $before = Carbon::parse($employed[0])->subDay()->toDateString();
        $after = Carbon::parse($employed[count($employed) - 1])->addDay()->toDateString();
        $today = EffectiveDays::today();
        $settings = $this->rules->all();

        $summary = ['worked' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0, 'missing' => 0];
        foreach ($days as $x) {
            $code = LeaveCode::tryFrom((string) $x['leave_code']);
            $kind = match (true) {
                $x['state'] === 'not_employed' || $x['date'] > $today => null,
                $x['state'] === 'missing' => 'missing',
                $code === null => $x['state'] === 'stored' ? 'worked' : null,
                $code === LeaveCode::Absent => 'absent',
                in_array($code, [LeaveCode::Friday, LeaveCode::Saturday, LeaveCode::OfficialHoliday, LeaveCode::Rest], true) => 'off',
                default => 'leave',   // sick, annual, casual, unpaid, other
            };
            if ($kind !== null) {
                $summary[$kind]++;
            }
        }

        return [
            'period' => [
                'group' => $type->value, 'start' => $p->start, 'end' => $p->end,
                'prev_date' => Employment::typeOn($e, $before) === null ? null : $before,
                'next_date' => $after > $today || Employment::typeOn($e, $after) === null ? null : $after,
                'is_locked' => PeriodGuard::isLocked($p),
            ],
            'fields' => DayRules::FIELDS[$type->value],
            'days' => array_values(array_map(fn (array $x) => [
                ...array_intersect_key($x, array_flip(['date', 'state', 'source', 'version', 'site_id', 'leave_code', ...EffectiveDays::VALUES])),
                'hints' => (object) $this->days->hints($type, $e, $x, $settings),
            ], $days)),
            'summary' => $summary,
        ];
    }

    /**
     * One group's employees on one date (those employed then, by code), with the day, its hints, the last site, the period totals and
     * whether the period is locked (on each row, so All's merged rows keep their own type's lock), plus the period's missing days per date.
     *
     * @return array{group: string, date: string, days: list<array{date: string, missing: int, off: bool}>, rows: list<array<string, mixed>>}
     */
    public function sheet(EmployeeType $g, string $date, ?string $ownerId = null): array
    {
        $today = EffectiveDays::today();
        if ($date > $today) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        // ponytail: whole group in PHP (~1,000 × 31 cells); move counts to SQL if a group outgrows it
        $p = Period::containing($g, $date);
        $employees = $this->roster->allOfType($g, $p->start, $p->end, $ownerId);
        $days = $this->days->forPeriod($p, $employees);
        $settings = $this->rules->all();
        $employed = array_values(array_filter($employees, fn (array $e) => $days[$e['id']][$date]['state'] !== 'not_employed'));
        $lastSites = $g === EmployeeType::Office ? [] : $this->lastSites(array_column($employed, 'id'), $date);

        $missing = array_fill_keys($p->days(), 0);
        foreach ($days as $byDate) {
            foreach ($byDate as $d) {
                $missing[$d['date']] += $d['state'] === 'missing' ? 1 : 0;
            }
        }
        $off = $this->days->offDays($p);
        $locked = PeriodGuard::isLocked($p);

        $rows = [];
        foreach ($employed as $e) {
            $day = $days[$e['id']][$date];
            $totals = ['worked' => 0, 'leave' => 0, 'absent' => 0, 'missing' => 0];
            foreach ($days[$e['id']] as $d) {
                $kind = match (true) {
                    $d['date'] > $today => null,
                    $d['state'] === 'missing' => 'missing',
                    $d['state'] === 'stored' && $d['leave_code'] === null => 'worked',
                    $d['state'] === 'stored' && $d['leave_code'] === 'absent' => 'absent',
                    $d['leave_code'] !== null => 'leave',
                    default => null,
                };
                if ($kind !== null) {
                    $totals[$kind]++;
                }
            }
            $site = $this->rowSite($g, $e, $day, $date, $lastSites[$e['id']] ?? null);
            $rows[] = [
                'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'department' => $e['department'], 'type' => $g->value],
                'site_id' => $site,
                'needs_site' => $site === null && $g !== EmployeeType::Engineer && ! Employment::offEveryCrewOn($e, $date),
                'day' => array_intersect_key($day, array_flip(['state', 'source', 'version', 'site_id', 'leave_code', ...EffectiveDays::VALUES])),
                'hints' => (object) $this->days->hints($g, $e, $day, $settings),
                'last_site_id' => $lastSites[$e['id']] ?? null,
                'assigned_site_id' => $g === EmployeeType::Office || $g === EmployeeType::Engineer ? null : Employment::homeSiteOn($e, $date),
                'totals' => $totals,
                'locked' => $locked,
            ];
        }

        return [
            'group' => $g->value,
            'date' => $date,
            'days' => array_map(fn (string $d, int $n) => ['date' => $d, 'missing' => $n, 'off' => $off[$d]], array_keys($missing), array_values($missing)),
            'rows' => $rows,
        ];
    }

    /**
     * Every type's sheet in one list by code (owner, 2026-11-08): each type is read in its own period, so its totals and hints stay right. The
     * days are the engineers' window and their off days; `missing` is null because a per-date count across different pay periods would be wrong.
     *
     * @return array{group: string, date: string, days: list<array{date: string, missing: null, off: bool}>, rows: list<array<string, mixed>>}
     */
    public function sheetOfEveryType(string $date, ?string $ownerId = null): array
    {
        // ponytail: unpaginated, the whole company in PHP, one sheet() per type; page it in SQL if the company outgrows one response
        $rows = [];
        $days = [];
        foreach (EmployeeType::cases() as $g) {
            $sheet = $this->sheet($g, $date, $ownerId);
            $rows = [...$rows, ...$sheet['rows']];
            if ($g === EmployeeType::Engineer) {
                $days = array_map(fn (array $d) => [...$d, 'missing' => null], $sheet['days']);
            }
        }
        usort($rows, fn (array $a, array $b) => $a['employee']['code'] <=> $b['employee']['code']);

        return ['group' => 'all', 'date' => $date, 'days' => $days, 'rows' => $rows];
    }

    /**
     * Where the employee is on $date, the one rule for the site filter: the day's work site, else a worker's or office employee's site on the date,
     * else the last site (office staff have none). Null when none applies, and for a day laborer taken off every crew on $date, whatever their last site.
     *
     * @param  array<string, mixed>  $employee  EmployeeRoster row
     * @param  array<string, mixed>  $day
     */
    private function rowSite(EmployeeType $g, array $employee, array $day, string $date, ?string $lastSite): ?string
    {
        if ($day['state'] === 'stored' && $day['site_id'] !== null && $day['leave_code'] === null) {
            return $day['site_id'];
        }

        return match (true) {
            $g === EmployeeType::Engineer => $lastSite,
            Employment::offEveryCrewOn($employee, $date) => null,
            default => Employment::homeSiteOn($employee, $date) ?? $lastSite,
        };
    }

    /**
     * The 21:00 alert's counts (owner, 2026-11-05), per group, for the open periods among the one containing $date and the one before,
     * up to $date: `missing` unrecorded days; `open` field days with an arrival and no departure (office never; a locked period never);
     * `crew_changes` the site managers' crew changes on $date (day laborers, the current period only). Counts only, never names (§9).
     * Archived employees are left out (owner, 2026-11-11): the roster's default.
     *
     * @return list<array{group: string, period_start: string, period_end: string, missing: int, open: int, crew_changes: int}>
     */
    public function missingCounts(string $date): array
    {
        $changes = $this->crew->changesOn($date);
        $crew = array_sum($changes['added']) + $changes['removed'];
        $counts = [];
        foreach (EmployeeType::cases() as $g) {
            $current = Period::containing($g, $date);
            foreach ([$current->previous(), $current] as $p) {
                if (! PeriodGuard::isLocked($p)) {
                    $missing = array_filter($this->missingDays($p), fn (array $d) => $d['date'] <= $date);
                    $counts[] = [
                        'group' => $g->value, 'period_start' => $p->start, 'period_end' => $p->end, 'missing' => count($missing),
                        'open' => $g === EmployeeType::Office ? 0 : $this->openCount($p, $date),
                        'crew_changes' => $g === EmployeeType::DayLaborer && $p->start === $current->start ? $crew : 0,
                    ];
                }
            }
        }

        return $counts;
    }

    /** @return array{sites: list<array<string, mixed>>, engineers: list<array<string, mixed>>} */
    public function skippedBySite(string $date): array
    {
        $missing = [];
        foreach (self::FIELD as $group) {
            $period = Period::containing($group, $date);
            if (! PeriodGuard::isLocked($period)) {
                $employees = $this->roster->allOfType($group, $date, $date);
                $days = $this->days->forPeriod($period, $employees);
                $missing[$group->value] = array_values(array_filter($employees, fn (array $e) => $days[$e['id']][$date]['state'] === 'missing'));
            }
        }
        $siteCounts = [];
        foreach ([...($missing['permanent_worker'] ?? []), ...($missing['day_laborer'] ?? [])] as $worker) {
            $site = Employment::homeSiteOn($worker, $date);
            if ($site !== null) {
                $siteCounts[$site] = ($siteCounts[$site] ?? 0) + 1;
            }
        }
        $sites = Site::whereKey(array_keys($siteCounts))->whereHas('managers')->orderBy('name_ar')->get()
            ->map(function (Site $site) use ($siteCounts): array {
                $id = (string) $site->getAttribute('id');

                return ['site_id' => $id, 'name_ar' => $site->name_ar, 'name_en' => $site->name_en, 'missing' => $siteCounts[$id]];
            })->all();

        $missingEngineers = array_column($missing['engineer'] ?? [], 'id');
        $engineers = [];
        if ($missingEngineers !== []) {
            $managers = DB::table('employee_manager_assignments')->whereIn('employee_id', $missingEngineers)
                ->whereNull('deleted_at')->where('effective_from', '<=', $date)
                ->selectRaw('distinct on (employee_id) employee_id, manager_id')->orderBy('employee_id')->orderByDesc('effective_from');
            $engineers = DB::query()->fromSub($managers, 'assignments')->join('users', 'users.id', '=', 'assignments.manager_id')
                ->whereNull('users.deleted_at')->where('users.is_active', true)
                ->selectRaw('users.id as manager_id, users.name as manager_name, count(*) as missing')
                ->groupBy('users.id', 'users.name')->orderBy('users.name')->get()->map(fn (object $row) => (array) $row)->all();
        }

        return ['sites' => array_values($sites), 'engineers' => array_values($engineers)];
    }

    /** Days of the period's employees up to $date with an arrival and no departure. */
    private function openCount(Period $p, string $date): int
    {
        $ids = array_column($this->roster->allOfType($p->group, $p->start, $p->end), 'id');

        return $ids === [] ? 0 : AttendanceDay::whereIn('employee_id', $ids)->whereBetween('date', [$p->start, min($p->end, $date)])
            ->whereNotNull('in_time')->whereNull('out_time')->count();
    }

    /**
     * Per employee, the site of the latest live stored day in the LAST_SITE_DAYS before $date whose site is an active work site (P31).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string> employee id → site id
     */
    private function lastSites(array $employeeIds, string $date): array
    {
        $d = Carbon::parse($date);

        return DB::table('attendance_days as d')
            ->join('sites as s', fn ($j) => $j->on('s.id', '=', 'd.site_id')->where('s.kind', 'work')->where('s.active', true)->whereNull('s.deleted_at'))
            ->whereNull('d.deleted_at')->whereIn('d.employee_id', $employeeIds)
            ->whereBetween('d.date', [$d->copy()->subDays(self::LAST_SITE_DAYS)->toDateString(), $d->copy()->subDay()->toDateString()])
            ->selectRaw('distinct on (d.employee_id) d.employee_id, d.site_id')
            ->orderBy('d.employee_id')->orderByDesc('d.date')
            ->pluck('site_id', 'employee_id')->all();
    }

    /**
     * Each day of $p under the type the employee had that day, computed in that type's own period, so a day laborer's day
     * keeps its half-month's lock and weekly off, and an office day its calendar month's.
     *
     * @param  list<array<string, mixed>>  $employees  EmployeeRoster rows
     * @return array<string, array<string, array<string, mixed>>> employee id → date → day
     */
    private function daysOfEveryType(Period $p, array $employees): array
    {
        $days = [];
        foreach (EmployeeType::cases() as $type) {
            for ($q = Period::containing($type, $p->start); $q->start <= $p->end; $q = $q->next()) {
                foreach ($this->days->forPeriod($q, $employees) as $id => $byDate) {
                    foreach ($byDate as $date => $d) {
                        if ($p->contains($date) && (! isset($days[$id][$date]) || $d['state'] !== 'not_employed')) {
                            $days[$id][$date] = $d;
                        }
                    }
                }
            }
        }

        return $days;
    }

    /** HR's «إظهار من تركوا العمل» (owner, 2026-11-11): honoured only for a holder of attendance.edit at `all`, otherwise ignored, never an error. */
    private function honoursWithArchived(bool $asked, ?Authenticatable $by): bool
    {
        return $asked && $by !== null && $this->authorize->decide($by, 'attendance.edit')->allows(Scope::All);
    }

    /**
     * One path, three answers for archived employees: the header count follows the grid, the lock dialog's list includes them, the 21:00 alert leaves them out.
     *
     * @return list<array{employee_id: string, code: int, name_ar: string, name_en: ?string, archived: bool, date: string}> by code then date
     */
    private function missingDays(Period $p, ?string $ownerId = null, bool $withArchived = false): array
    {
        // ponytail: in-PHP count over ~100 employees × 31 days; move to SQL if a group grows past ~1,000
        $employees = $this->roster->withArchived($withArchived)->allOfType($p->group, $p->start, $p->end, $ownerId);
        $days = $this->days->forPeriod($p, $employees);
        $missing = [];
        foreach ($employees as $e) {
            foreach ($days[$e['id']] as $d) {
                if ($d['state'] === 'missing') {
                    $missing[] = ['employee_id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'archived' => $e['archived'], 'date' => $d['date']];
                }
            }
        }

        return $missing;
    }

    /** The period starting at $start, else the one containing $date, else the current one. */
    public function periodAt(EmployeeType $g, ?string $start, ?string $date = null): Period
    {
        if ($start === null) {
            return Period::containing($g, $date ?? EffectiveDays::today());
        }
        try {
            return Period::startingAt($g, $start);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['period_start' => __('attendance.invalid_period')]);
        }
    }

    /** @return array{locked_by: ?array{id: string, name: string}, locked_at: ?string, reopened_by: ?array{id: string, name: string}, reopened_at: ?string, reopen_reason: ?string}|null */
    private function lock(Period $p): ?array
    {
        $lock = AttendanceLock::where(['employee_type' => $p->group->value, 'period_start' => $p->start])->first();
        if ($lock === null) {
            return null;
        }
        $names = User::withTrashed()->whereIn('id', array_filter([$lock->locked_by, $lock->reopened_by]))->pluck('name', 'id');
        $user = fn (?string $id) => $id === null ? null : ['id' => $id, 'name' => (string) ($names[$id] ?? '')];

        return [
            'locked_by' => $user($lock->locked_by),
            'locked_at' => $lock->locked_at?->toJSON(),
            'reopened_by' => $user($lock->reopened_by),
            'reopened_at' => $lock->reopened_at?->toJSON(),
            'reopen_reason' => $lock->reopen_reason,
        ];
    }
}
