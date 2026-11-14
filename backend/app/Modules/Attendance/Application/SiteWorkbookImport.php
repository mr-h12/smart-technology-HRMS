<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Attendance\Infrastructure\XlsxAttendanceReader;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\Gender;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Database\Precision;
use App\Support\Money\Decimal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The site workbook's import (ملف الموقع, calculations §14 W2–W17): HR's upload of a SiteWorkbookTemplate file. Each row names someone
 * from the file's name list, or a new day laborer by their national ID (W5), and is written through AttendanceDays::writeCell as HR (no
 * owner), source `imported`, at the file's site. The preview creates the new laborers and runs those writes, then rolls everything back
 * (W15), so the late and overtime minutes it shows are what the write path stores; the confirm runs the same and commits it.
 *
 * @phpstan-type At array{sheet: string, row: int, date: string, name: ?string}
 * @phpstan-type RowError array{sheet: string, row: int, date: string, name: ?string, error: string, message: string}
 * @phpstan-type Input array{version: ?int, site_id: ?string, in_time?: string, out_time?: string, ot_minutes?: int, advance_amount?: numeric-string, note?: string}
 * @phpstan-type Write array{at: At, employee: array<string, mixed>, date: string, type: ?EmployeeType, input: Input, matched: bool, national_id: ?string, key: string, live: ?AttendanceDay, conflict: bool}
 * @phpstan-type NewRow array{at: At, date: string, input: Input, phone: ?string, residence_id: ?string, residence: ?string, wage: ?numeric-string}
 * @phpstan-type Laborer array{national_id: string, name_ar: string, rows: list<NewRow>}
 * @phpstan-type Hired array{name_ar: string, national_id: string, phone: ?string, residence: ?string, proposed_wage: ?string, gender: string, first_date: string, days: int, employee_id: string, code: int, proposal: bool}
 * @phpstan-type Plan array{site: array{id: string, name_ar: string, name_en: ?string}, from: string, to: string, rows: int, token: string, writes: list<Write>, laborers: list<Laborer>, errors: list<RowError>}
 */
final class SiteWorkbookImport
{
    /** A technical cap per day sheet (W16), not a business setting. */
    public const MAX_ROWS = 2000;

    /** The longest name a new day laborer may have: employees.name_ar is varchar(255), a column limit, not a business setting. */
    private const NAME_MAX = 255;

    /** The row codes this import gives itself (attendance.import.*); the others are the write path's and the day rules'. */
    private const OWN_CODES = [
        'name_required', 'status_required', 'unknown_employee', 'national_id_required', 'national_id_taken', 'national_id_name_mismatch', 'new_laborer_not_allowed',
        'duplicate_row', 'unknown_status', 'bad_time', 'bad_amount', 'note_too_long', 'name_too_long', 'bad_mobile', 'unknown_residence', 'choice_required',
    ];

    public function __construct(
        private readonly SiteWorkbookTemplate $template,
        private readonly SiteService $sites,
        private readonly AttendanceImport $import,
        private readonly AttendanceDays $writes,
        private readonly EmployeeRoster $roster,
        private readonly DayCrew $crew,
        private readonly BusinessRules $rules,
        private readonly XlsxAttendanceReader $reader,
        private readonly AuthorizeAction $authorize,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    /**
     * W15: the file's rows written and rolled back, new laborers included. A new day laborer's row needs $by to hold employee.create at
     * all scope, else it is new_laborer_not_allowed (W5).
     *
     * @return array{site: array{id: string, name_ar: string, name_en: ?string}, from: string, to: string, token: string, summary: array{rows: int, changes: int, unchanged: int, errors: int, conflicts: int, new_laborers: int, advances_total: string}, changes: list<array<string, mixed>>, new_laborers: list<array<string, mixed>>, errors: list<RowError>}
     */
    public function preview(string $path, ?Authenticatable $by): array
    {
        $plan = $this->plan($path, $by);
        DB::beginTransaction();
        try {
            $result = $this->apply($plan, null);
        } finally {
            DB::rollBack();
        }
        // Spec «Preview»: who would be created; the employee and its ID were rolled back with everything else.
        $laborers = array_map(fn (array $l) => array_diff_key($l, ['employee_id' => true, 'code' => true, 'proposal' => true]), $result['new_laborers']);

        return [
            'site' => $plan['site'],
            'from' => $plan['from'],
            'to' => $plan['to'],
            'token' => $plan['token'],
            'summary' => [
                'rows' => $plan['rows'], 'changes' => count($result['changes']), 'unchanged' => $result['unchanged'], 'errors' => count($result['errors']),
                'conflicts' => $result['conflicts'], 'new_laborers' => count($laborers), 'advances_total' => $result['advances_total'],
            ],
            'changes' => $result['changes'],
            'new_laborers' => $laborers,
            'errors' => $result['errors'],
        ];
    }

    /**
     * W13, W15: the preview's writes, committed in one transaction, if the stored days and the new national IDs are still what the
     * preview read (its token); else nothing is saved. $choices is keep or replace by conflict key; $by as preview().
     *
     * @param  array<string, string>  $choices
     * @return array{applied: int, unchanged: int, kept: int, advances_total: string, new_laborers: list<array{employee_id: string, code: int, name_ar: string, proposal: bool}>, errors: list<RowError>}
     */
    public function confirm(string $path, string $token, array $choices, ?Authenticatable $by): array
    {
        $plan = $this->plan($path, $by);
        if (! hash_equals($plan['token'], $token)) {
            throw AttendanceRefused::because(AttendanceRefusal::PreviewStale);
        }
        $result = DB::transaction(fn () => $this->apply($plan, $choices));

        return [
            'applied' => count($result['changes']), 'unchanged' => $result['unchanged'], 'kept' => $result['kept'], 'advances_total' => $result['advances_total'],
            'new_laborers' => array_map(fn (array $l) => ['employee_id' => $l['employee_id'], 'code' => $l['code'], 'name_ar' => $l['name_ar'], 'proposal' => $l['proposal']], $result['new_laborers']),
            'errors' => $result['errors'],
        ];
    }

    /**
     * W3–W9, W13 and the token: each row resolved and checked in W17 order, then the stored day of each planned (employee, date) read
     * once. The token is the SHA-256 of those reads (employee, date, version, site) in employee_id, date order, then of each new
     * national ID's resolution (`new`, the listed employee it is, or `taken`), so a laborer created since the preview makes it stale.
     *
     * @return Plan
     */
    private function plan(string $path, ?Authenticatable $by): array
    {
        [$site, $from, $to, $rows] = $this->read($path);
        // Spec «Endpoints and permissions»: only a caller holding employee.create at all scope creates a new day laborer (W5).
        $mayCreate = $by !== null && $this->authorize->decide($by, 'employee.create')->allows(Scope::All);
        $names = [];
        $listed = [];
        foreach ($this->template->names($site['id'], $from, $to, $site['managers']) as $n) {
            $names[(string) XlsxAttendanceReader::text($n['entry'])] = $n['employee'];
            $listed[(string) $n['employee']['id']] = $n['employee'];
        }
        // W6: the present word in either language, then every leave day-type (an inactive one too, so the write refuses site_inactive).
        $statuses = $this->import->choices(EmployeeType::Office)[0];
        $residences = self::residences();
        $settings = $this->rules->all();
        $amount = [...AttendanceDays::FORMAT['advance_amount'], EmployeeRules::POSITIVE];
        [$am, $pm] = $periods = SiteWorkbookTemplate::periods();

        $writes = [];
        $laborers = [];
        $errors = [];
        $seen = [];
        $holders = [];      // national ID => the id of the employee holding it, or null: read once each, the token's tail
        $firstNames = [];   // a new national ID => the name on its first row (date order)
        foreach ($rows as $r) {
            // Cells arrive through XlsxAttendanceReader::text(), so a national ID or mobile in Arabic-Indic digits reads as ASCII.
            [$name, $status, $in, $inPeriod, $out, $outPeriod, $advance, $note, $nationalId, $mobile, $residence, $wage] = array_pad($r['cells'], 12, null);
            $at = ['sheet' => $r['sheet'], 'row' => $r['row'], 'date' => $r['date'], 'name' => $name];
            $employee = $names[(string) $name] ?? null;
            [$new, $matched, $person] = [null, false, null];     // a new laborer's national ID; found by national ID; the person's refusal
            // W4, W5: a text that is no entry and not shaped like one (`… · <digits>`) is a new day laborer, known by their national ID.
            // Columns I–L of an entry's row are never read. Without employee.create at all scope the ID is never looked up, so the
            // file cannot ask whose it is (security review L2).
            if ($employee === null && $name !== null && $status !== null && preg_match('/ · \d+$/Du', $name) !== 1) {
                if (! $mayCreate) {
                    $person = 'new_laborer_not_allowed';
                } elseif ($nationalId === null || preg_match('/^\d{14}$/D', $nationalId) !== 1) {
                    $person = 'national_id_required';
                } else {
                    $holder = array_key_exists($nationalId, $holders) ? $holders[$nationalId] : ($holders[$nationalId] = $this->roster->idWithNationalId($nationalId));
                    $employee = $holder === null ? null : $listed[$holder] ?? null;
                    $matched = $employee !== null;
                    $firstName = $firstNames[$nationalId] ??= $name;
                    $person = match (true) {
                        $holder !== null => $matched ? null : 'national_id_taken',
                        $firstName !== $name => 'national_id_name_mismatch',
                        default => null,
                    };
                    $new = $holder === null && $person === null ? $nationalId : null;
                }
            }
            $who = $new ?? ($employee === null ? null : (string) $employee['id']);
            $choice = $statuses[AttendanceImport::key((string) $status)] ?? null;
            $times = [self::time($in, $inPeriod ?? $am, $periods), self::time($out, $outPeriod ?? $pm, $periods)];     // W8: blank is ص in, م out
            $residenceKey = AttendanceImport::key((string) $residence);
            $error = match (true) {
                $name === null => 'name_required',
                $status === null => 'status_required',
                $person !== null => $person,
                $who === null => 'unknown_employee',                                      // W4
                isset($seen[$r['date']][$who]) => 'duplicate_row',
                $choice === null => 'unknown_status',
                in_array(false, $times, true) => 'bad_time',
                ($times[0] === null) !== ($times[1] === null) => 'times_need_both',     // W8: an imported day is never left open
                $advance !== null && ! self::passes($advance, $amount) => 'bad_amount',
                $note !== null && ! self::passes($note, AttendanceDays::FORMAT['note']) => 'note_too_long',
                // A new laborer's cells; every one is format-checked, so nothing from the file can overflow a column (a 500).
                $new !== null && mb_strlen((string) $name) > self::NAME_MAX => 'name_too_long',
                $new !== null && $mobile !== null && ! self::passes($mobile, [EmployeeRules::MOBILE]) => 'bad_mobile',
                $new !== null && $residence !== null && ! isset($residences[$residenceKey]) => 'unknown_residence',
                $new !== null && $wage !== null && ! self::passes($wage, $amount) => 'bad_amount',
                default => null,
            };
            if ($who !== null && $status !== null) {
                $seen[$r['date']][$who] = true;
            }
            if ($error !== null) {
                $errors[] = self::error($at, $error);

                continue;
            }

            $type = $employee === null ? EmployeeType::DayLaborer : Employment::typeOn($employee, $r['date']);
            // W6: «حاضر» is this site, or office present (no site) for office staff; any other status is its leave day-type.
            $input = ['version' => null, 'site_id' => $choice['site_id'] ?? ($type === EmployeeType::Office ? null : $site['id'])];
            // W7: an unsent time keeps what is stored, or takes the field shift on a new present day.
            if (is_string($times[0]) && is_string($times[1])) {
                $input['in_time'] = $times[0];
                $input['out_time'] = $times[1];
                // W11: HR's confirm approves the overtime the times give, even over a site manager's day stored with 0.
                if (in_array($type, [EmployeeType::Engineer, EmployeeType::DayLaborer], true) && $input['site_id'] === $site['id'] && $times[1] >= $times[0]) {
                    // A new laborer has no pay row, so no shift of their own: the settings' field shift.
                    $shift = EffectiveDays::fieldShift($employee ?? ['shifts' => []], $r['date'], $settings);
                    $input['ot_minutes'] = DayRules::workerMinutes($shift['start'], $shift['end'], $times[0], $times[1])['ot'];
                }
            }
            if ($advance !== null) {
                $input['advance_amount'] = Decimal::of($advance, 'advance_amount');
            }
            if ($note !== null) {
                $input['note'] = $note;
            }
            if ($employee === null) {       // a row left with no error and no employee is a new laborer's: $new is set
                $new = (string) $new;
                $laborers[$new] ??= ['national_id' => $new, 'name_ar' => (string) $name, 'rows' => []];
                $laborers[$new]['rows'][] = [
                    'at' => $at, 'date' => $r['date'], 'input' => $input, 'phone' => $mobile, 'wage' => $wage === null ? null : Decimal::of($wage, 'proposed_wage'),
                    'residence_id' => $residences[$residenceKey]['id'] ?? null, 'residence' => $residences[$residenceKey]['name'] ?? null,
                ];

                continue;
            }
            $writes[] = ['at' => $at, 'employee' => $employee, 'date' => $r['date'], 'type' => $type, 'input' => $input, 'matched' => $matched, 'national_id' => null];
        }

        $live = [];
        $days = AttendanceDay::whereIn('employee_id', array_unique(array_map(fn (array $w) => (string) $w['employee']['id'], $writes)))
            ->whereIn('date', array_unique(array_column($writes, 'date')))->get();
        foreach ($days as $d) {
            $live[$d->employee_id.'/'.substr((string) $d->date, 0, 10)] = $d;
        }
        $kinds = Site::withTrashed()->whereKey($days->pluck('site_id')->filter()->unique()->values()->all())->pluck('kind', 'id')->all();

        // Rows are written in (employee_id, date) order, as bulk() locks them, so an import and a bulk write cannot deadlock.
        usort($writes, fn (array $a, array $b) => strcmp($a['employee']['id'].$a['date'], $b['employee']['id'].$b['date']));
        $planned = [];
        $reads = [];
        foreach ($writes as $w) {
            $key = $w['employee']['id'].'/'.$w['date'];
            $day = $live[$key] ?? null;
            $w['input']['version'] = $day?->version;
            // W13: a day stored at another work site, whatever the file's status.
            $conflict = $day?->site_id !== null && $day->site_id !== $site['id'] && ($kinds[$day->site_id] ?? null) === SiteKind::Work->value;
            $planned[] = [...$w, 'key' => $key, 'live' => $day, 'conflict' => $conflict];
            $reads[] = implode('|', [$key, $day?->version, $day?->site_id]);
        }
        ksort($holders, SORT_STRING);
        foreach ($holders as $nationalId => $holder) {
            $reads[] = $nationalId.'|'.($holder === null ? 'new' : (isset($listed[$holder]) ? $holder : 'taken'));
        }

        return [
            'site' => ['id' => $site['id'], 'name_ar' => $site['name_ar'], 'name_en' => $site['name_en']],
            'from' => $from,
            'to' => $to,
            'rows' => count($rows),
            'token' => hash('sha256', implode("\n", $reads)),
            'writes' => $planned,
            'laborers' => array_values($laborers),
            'errors' => $errors,
        ];
    }

    /**
     * W5 and the hire date the controller pinned (plan «Pinned here», 2026-11-12): each new day laborer is created before any row is
     * written. Their rows after today (future_date) or in a locked day-laborer period (period_locked) are refused first, so the type
     * change and the crew row are never dated in the future or into a locked period (the M1 rule). The hire date is the earliest
     * remaining date and each value the first non-blank one over the remaining rows, by date then row; no remaining row hires nobody.
     * Gender is male, the owner's default until the file has a gender column (spec «Open»).
     *
     * @param  Plan  $plan
     * @return array{array<array-key, Hired>, list<Write>, list<RowError>} the hired by national ID, their rows to write, the refused rows
     */
    private function hire(array $plan): array
    {
        $today = EffectiveDays::today();
        $hired = [];
        $writes = [];
        $errors = [];
        foreach ($plan['laborers'] as $l) {
            $rows = [];
            foreach ($l['rows'] as $r) {
                // Inside the caller's transaction: lockedFrom holds the lock table until it ends.
                $refusal = match (true) {
                    $r['date'] > $today => AttendanceRefusal::FutureDate,
                    PeriodGuard::lockedFrom($r['date'], EmployeeType::DayLaborer) => AttendanceRefusal::PeriodLocked,
                    default => null,
                };
                if ($refusal === null) {
                    $rows[] = $r;
                } else {
                    $errors[] = [...$r['at'], ...AttendanceImport::reason(AttendanceRefused::because($refusal))];
                }
            }
            if ($rows === []) {
                continue;
            }
            $phone = $residenceId = $residence = $wage = null;
            foreach ($rows as $r) {         // each value from the first row that gives one (a residence's id and name come together)
                $phone ??= $r['phone'];
                $residenceId ??= $r['residence_id'];
                $residence ??= $r['residence'];
                $wage ??= $r['wage'];
            }
            $identity = ['name_ar' => $l['name_ar'], 'gender' => Gender::Male->value, 'phone' => $phone, 'national_id' => $l['national_id'], 'residence_id' => $residenceId];
            try {
                // propose()'s own transaction is a savepoint here: a refused insert rolls back to it and leaves ours usable, not aborted.
                $made = $this->crew->propose($identity, $plan['site']['id'], $rows[0]['date'], $wage);
            } catch (UniqueConstraintViolationException) {
                // Someone was given this national ID after the plan read it (a racing request): the whole file is stale. Converted,
                // never reported: PostgreSQL's DETAIL line quotes the national ID (spec «Security notes»).
                throw new ConcurrencyConflict;
            }
            $hired[$l['national_id']] = [
                'name_ar' => $l['name_ar'], 'national_id' => $l['national_id'], 'phone' => $phone, 'residence' => $residence,
                'proposed_wage' => $wage === null ? null : bcadd($wage, '0', Precision::MONEY_SCALE), 'gender' => $identity['gender'], 'first_date' => $rows[0]['date'],
                'days' => 0, 'employee_id' => $made['id'], 'code' => $made['code'], 'proposal' => $made['proposal_id'] !== null,
            ];
            $employee = (array) $this->roster->find($made['id']);
            foreach ($rows as $r) {
                $writes[] = [
                    'at' => $r['at'], 'employee' => $employee, 'date' => $r['date'], 'type' => EmployeeType::DayLaborer, 'input' => $r['input'],
                    'matched' => false, 'national_id' => $l['national_id'], 'key' => $made['id'].'/'.$r['date'], 'live' => null, 'conflict' => false,
                ];
            }
        }

        return [$hired, $writes, $errors];
    }

    /**
     * W5, W12, W13, W15: the new laborers created (hire()), then each planned row written as HR, each in its own savepoint, then read
     * back; the caller owns the transaction. $choices (keep or replace by conflict key) is the confirm's; null is the preview, which
     * replaces every conflict. `conflicts` counts the conflict rows in `changes`, the ones the screen asks about; `advances_total` sums
     * the rows saved or unchanged; a new laborer's `days` counts their rows saved.
     *
     * @param  Plan  $plan
     * @param  array<string, string>|null  $choices
     * @return array{changes: list<array<string, mixed>>, unchanged: int, kept: int, conflicts: int, errors: list<RowError>, advances_total: string, new_laborers: list<Hired>}
     */
    private function apply(array $plan, ?array $choices): array
    {
        [, $names] = $this->import->choices(EmployeeType::Office);
        $present = (string) __('attendance.template.present');
        [$hired, $hires, $refused] = $this->hire($plan);
        $days = [];                         // a new laborer's rows saved, by national ID
        $changes = [];
        $errors = [...$plan['errors'], ...$refused];
        $unchanged = 0;
        $kept = 0;
        $conflicts = 0;
        $total = bcadd('0', '0', Precision::MONEY_SCALE);
        // A new laborer's rows come last: their ids are the newest, and no other request can see, so lock, those rows yet.
        foreach ([...$plan['writes'], ...$hires] as $w) {
            // W13: a conflict is written only on `replace`; `keep` writes nothing. Other rows ignore $choices.
            $choice = $choices === null || ! $w['conflict'] ? 'replace' : ($choices[$w['key']] ?? null);
            if ($choice === 'keep') {
                $kept++;

                continue;
            }
            // No choice writes nothing either: a dry run gives the refusal the preview showed for the row (so the screen asked no
            // choice, B3 review M2), else the row is choice_required.
            $dryRun = $choice !== 'replace';
            try {
                $this->writes->writeCell($w['employee'], $w['date'], $w['input'], null, 'imported', $dryRun);
            } catch (AttendanceRefused|ConcurrencyConflict|DayRulesViolated $e) {
                $errors[] = [...$w['at'], ...AttendanceImport::reason($e)];

                continue;
            }
            if ($dryRun) {
                $errors[] = self::error($w['at'], 'choice_required');

                continue;
            }
            $total = bcadd($total, $w['input']['advance_amount'] ?? '0', Precision::MONEY_SCALE);
            $after = AttendanceDay::where(['employee_id' => $w['employee']['id'], 'date' => $w['date']])->firstOrFail();
            if ($after->version === $w['input']['version']) {
                $unchanged++;   // W15: a row that changes nothing writes nothing

                continue;
            }
            $from = $w['live'] === null ? null : self::day($w['live'], $names, $present);
            $conflicts += (int) $w['conflict'];
            $new = $w['national_id'] !== null;
            if ($new) {
                $days[$w['national_id']] = ($days[$w['national_id']] ?? 0) + 1;
            }
            $changes[] = [
                // Spec «Preview»: a new laborer has no key, id or code there, since the preview rolls them back.
                'key' => $new ? null : $w['key'], 'sheet' => $w['at']['sheet'], 'row' => $w['at']['row'], 'date' => $w['date'],
                'employee_id' => $new ? null : (string) $w['employee']['id'], 'code' => $new ? null : (int) $w['employee']['code'], 'name_ar' => $w['employee']['name_ar'],
                'name_en' => $w['employee']['name_en'], 'type' => $w['type']?->value, 'new_laborer' => $new, 'matched_by_national_id' => $w['matched'],
                'conflict_site' => $w['conflict'] ? ($from['site'] ?? null) : null,
                'from' => $from,
                'to' => self::day($after, $names, $present),
            ];
        }
        $inSheetOrder = fn (array $a, array $b) => [$a['date'], $a['row']] <=> [$b['date'], $b['row']];
        usort($changes, $inSheetOrder);
        usort($errors, $inSheetOrder);

        return [
            'changes' => $changes, 'unchanged' => $unchanged, 'kept' => $kept, 'conflicts' => $conflicts, 'errors' => $errors, 'advances_total' => $total,
            'new_laborers' => array_values(array_map(fn (array $h) => [...$h, 'days' => $days[$h['national_id']] ?? 0], $hired)),
        ];
    }

    /**
     * W5: each residence by AttendanceImport::key of its name; a key two residences share matches none (unknown_residence).
     *
     * @return array<string, array{id: string, name: string}>
     */
    private static function residences(): array
    {
        $byKey = [];
        foreach (Residence::pluck('name_ar', 'id') as $id => $name) {
            $byKey[AttendanceImport::key((string) $name)][] = ['id' => (string) $id, 'name' => (string) $name];
        }

        return array_map(fn (array $same) => $same[0], array_filter($byKey, fn (array $same) => count($same) === 1));
    }

    /**
     * W2: the site and range from `lists` D1:D3, then each visible day sheet's date and header row; the data rows by date, then row.
     *
     * @return array{array{id: string, name_ar: string, name_en: ?string, managers: list<array{id: string, name: string}>}, string, string, list<array{sheet: string, row: int, date: string, cells: list<?string>}>}
     */
    private function read(string $path): array
    {
        // C and E: the arrival and departure (W8); at most a day sheet per date and `lists` (security review A2).
        $sheets = $this->reader->sheets($path, $this->maxRows, [2, 4], SiteWorkbookTemplate::MAX_DAYS + 1);
        $lists = array_column($sheets, 'rows', 'name')['lists'] ?? throw self::mismatch();
        $siteId = $lists[1][3] ?? null;
        $dates = SiteWorkbookTemplate::dates($lists[2][3] ?? null, $lists[3][3] ?? null);
        // Str::isUuid first: Postgres answers a malformed uuid with a query error.
        if ($siteId === null || ! Str::isUuid($siteId) || $dates === null) {
            throw self::mismatch();
        }
        [$from, $to] = [$dates[0], $dates[count($dates) - 1]];
        try {
            /** @var array{id: string, name_ar: string, name_en: ?string, kind: string, managers: list<array{id: string, name: string}>} $site */
            $site = $this->sites->show($siteId);
        } catch (ModelNotFoundException) {
            throw self::mismatch();
        }
        if ($site['kind'] !== SiteKind::Work->value) {
            throw self::mismatch();
        }

        $byDayMonth = [];
        foreach ($dates as $d) {
            $byDayMonth[substr($d, 8, 2).'-'.substr($d, 5, 2)] = $d;       // dd-mm; at most 31 days never repeat one
        }
        $header = array_map(XlsxAttendanceReader::text(...), SiteWorkbookTemplate::columns());
        $rows = [];
        $found = [];
        foreach ($sheets as $sheet) {
            if ($sheet['name'] === 'lists') {
                continue;
            }
            $date = $byDayMonth[mb_substr((string) XlsxAttendanceReader::text($sheet['name']), -5)] ?? null;
            if ($date === null || isset($found[$date]) || ($sheet['rows'][2] ?? []) !== $header) {
                throw self::mismatch();
            }
            $found[$date] = true;
            foreach ($sheet['rows'] as $n => $cells) {
                // W3: a row with only ص/م (D, F) filled is blank, as when ص is filled down a column ahead of the names.
                if ($n > 2 && array_filter(array_diff_key($cells, [3 => true, 5 => true]), fn (?string $c) => $c !== null) !== []) {
                    $rows[] = ['sheet' => $sheet['name'], 'row' => $n, 'date' => $date, 'cells' => $cells];
                }
            }
        }
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['row']] <=> [$b['date'], $b['row']]);

        return [$site, $from, $to, $rows];
    }

    /**
     * A stored day as the preview shows it: the site or leave day-type in the request language, or the present word for office present.
     *
     * @param  array<array-key, string>  $names  AttendanceImport::choices()' site names by id
     * @return array{site: ?string, in_time: ?string, out_time: ?string, late_minutes: ?int, ot_minutes: ?int, advance_amount: ?string, note: ?string}
     */
    private static function day(AttendanceDay $d, array $names, string $present): array
    {
        $time = fn (mixed $t) => $t === null ? null : substr((string) $t, 0, 5);
        $minutes = fn (mixed $m) => $m === null ? null : (int) $m;

        return [
            'site' => $d->site_id === null ? $present : ($names[$d->site_id] ?? null),
            'in_time' => $time($d->in_time),
            'out_time' => $time($d->out_time),
            'late_minutes' => $minutes($d->late_minutes),
            'ot_minutes' => $minutes($d->ot_minutes),
            'advance_amount' => $d->advance_amount,
            'note' => $d->note,
        ];
    }

    /**
     * W8: `H` or `H:MM` (the reader turns an Excel time into `HH:MM`) as `HH:MM`; null when blank, false when it is no time of day. An hour
     * of 1–12 is 12-hour, in $period (ص or م, the cell beside it or the column's default), so 12 ص is 00:MM; 0 and 13–23 are 24-hour.
     *
     * @param  array{string, string}  $periods  ص and م
     */
    private static function time(?string $value, string $period, array $periods): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?$/D', $value, $m) !== 1 || (int) $m[1] > 23 || (int) ($m[2] ?? 0) > 59) {
            return false;
        }
        $hour = (int) $m[1];
        if ($hour >= 1 && $hour <= 12) {
            $pm = array_search($period, $periods, true);
            if ($pm === false) {
                return false;   // a ص/م cell holding anything else (the dropdown blocks it; a paste does not)
            }
            $hour = $hour % 12 + 12 * $pm;
        }

        return sprintf('%02d:%s', $hour, $m[2] ?? '00');
    }

    /**
     * Whether $value passes AttendanceDays::FORMAT's rules for the day's field, so the limits stay in one place.
     *
     * @param  list<string>  $rules
     */
    private static function passes(string $value, array $rules): bool
    {
        return Validator::make(['value' => $value], ['value' => $rules])->passes();
    }

    /**
     * @param  At  $at
     * @return RowError
     */
    private static function error(array $at, string $code): array
    {
        return [...$at, 'error' => $code, 'message' => (string) __((in_array($code, self::OWN_CODES, true) ? 'attendance.import.' : 'attendance.rule.').$code)];
    }

    private static function mismatch(): AttendanceRefused
    {
        return AttendanceRefused::because(AttendanceRefusal::TemplateMismatch);
    }
}
