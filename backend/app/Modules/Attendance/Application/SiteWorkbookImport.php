<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\XlsxAttendanceReader;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Database\Precision;
use App\Support\Money\Decimal;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The site workbook's import (ملف الموقع, calculations §14 W2–W17): HR's upload of a SiteWorkbookTemplate file. Each row names someone
 * from the file's name list and is written through AttendanceDays::writeCell as HR (no owner), source `imported`, at the file's site.
 * The preview runs those writes and rolls them back (W15), so the late and overtime minutes it shows are what the write path stores;
 * the confirm runs the same writes and commits them.
 *
 * @phpstan-type At array{sheet: string, row: int, date: string, name: ?string}
 * @phpstan-type RowError array{sheet: string, row: int, date: string, name: ?string, error: string, message: string}
 * @phpstan-type Input array{version: ?int, site_id: ?string, in_time?: string, out_time?: string, ot_minutes?: int, advance_amount?: numeric-string, note?: string}
 * @phpstan-type Write array{at: At, employee: array<string, mixed>, date: string, type: ?EmployeeType, input: Input, key: string, live: ?AttendanceDay, conflict: bool}
 * @phpstan-type Plan array{site: array{id: string, name_ar: string, name_en: ?string}, from: string, to: string, rows: int, token: string, writes: list<Write>, errors: list<RowError>}
 */
final class SiteWorkbookImport
{
    /** A technical cap per day sheet (W16), not a business setting. */
    public const MAX_ROWS = 2000;

    /** The row codes this import gives itself (attendance.import.*); the others are the write path's and the day rules'. */
    private const OWN_CODES = ['name_required', 'status_required', 'unknown_employee', 'duplicate_row', 'unknown_status', 'bad_time', 'bad_amount', 'note_too_long', 'choice_required'];

    public function __construct(
        private readonly SiteWorkbookTemplate $template,
        private readonly SiteService $sites,
        private readonly AttendanceImport $import,
        private readonly AttendanceDays $writes,
        private readonly BusinessRules $rules,
        private readonly XlsxAttendanceReader $reader,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    /**
     * W15: the file's rows written and rolled back. $mayCreate (employee.create at all scope) is for new day laborers (W5), not built
     * yet: a typed name that is no entry is unknown_employee.
     *
     * @return array{site: array{id: string, name_ar: string, name_en: ?string}, from: string, to: string, token: string, summary: array{rows: int, changes: int, unchanged: int, errors: int, conflicts: int, new_laborers: int, advances_total: string}, changes: list<array<string, mixed>>, new_laborers: list<array<string, mixed>>, errors: list<RowError>}
     */
    public function preview(string $path, bool $mayCreate): array
    {
        $plan = $this->plan($path);
        DB::beginTransaction();
        try {
            $result = $this->apply($plan, null);
        } finally {
            DB::rollBack();
        }

        return [
            'site' => $plan['site'],
            'from' => $plan['from'],
            'to' => $plan['to'],
            'token' => $plan['token'],
            'summary' => [
                'rows' => $plan['rows'], 'changes' => count($result['changes']), 'unchanged' => $result['unchanged'], 'errors' => count($result['errors']),
                'conflicts' => $result['conflicts'], 'new_laborers' => 0, 'advances_total' => $result['advances_total'],
            ],
            'changes' => $result['changes'],
            'new_laborers' => [],
            'errors' => $result['errors'],
        ];
    }

    /**
     * W13, W15: the preview's writes, committed in one transaction, if the stored days are still those the preview read (its token);
     * else nothing is saved. $choices is keep or replace by conflict key. $mayCreate is for new day laborers (W5), not built yet.
     *
     * @param  array<string, string>  $choices
     * @return array{applied: int, unchanged: int, kept: int, advances_total: string, new_laborers: list<array<string, mixed>>, errors: list<RowError>}
     */
    public function confirm(string $path, string $token, array $choices, bool $mayCreate): array
    {
        $plan = $this->plan($path);
        if (! hash_equals($plan['token'], $token)) {
            throw AttendanceRefused::because(AttendanceRefusal::PreviewStale);
        }
        $result = DB::transaction(fn () => $this->apply($plan, $choices));

        return [
            'applied' => count($result['changes']), 'unchanged' => $result['unchanged'], 'kept' => $result['kept'],
            'advances_total' => $result['advances_total'], 'new_laborers' => [], 'errors' => $result['errors'],
        ];
    }

    /**
     * W3–W9, W13 and the token: each row resolved and checked in W17 order, then the stored day of each planned (employee, date) read
     * once. The token is the SHA-256 of those reads (employee, date, version, site) in employee_id, date order.
     *
     * @return Plan
     */
    private function plan(string $path): array
    {
        [$site, $from, $to, $rows] = $this->read($path);
        $names = [];
        foreach ($this->template->names($site['id'], $from, $to, $site['managers']) as $n) {
            $names[(string) XlsxAttendanceReader::text($n['entry'])] = $n['employee'];
        }
        // W6: the present word in either language, then every leave day-type (an inactive one too, so the write refuses site_inactive).
        $statuses = $this->import->choices(EmployeeType::Office)[0];
        $settings = $this->rules->all();

        $writes = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $r) {
            [$name, $status, $in, $out, $advance, $note] = array_pad($r['cells'], 6, null);
            $at = ['sheet' => $r['sheet'], 'row' => $r['row'], 'date' => $r['date'], 'name' => $name];
            $employee = $names[(string) $name] ?? null;
            $choice = $statuses[AttendanceImport::key((string) $status)] ?? null;
            $times = [self::time($in), self::time($out)];
            $error = match (true) {
                $name === null => 'name_required',
                $status === null => 'status_required',
                // W4. W5 (not built yet) makes a text that is not shaped like an entry (`… · <digits>`) a new day laborer.
                $employee === null => 'unknown_employee',
                isset($seen[$r['date']][(string) $employee['id']]) => 'duplicate_row',
                $choice === null => 'unknown_status',
                in_array(false, $times, true) => 'bad_time',
                ($times[0] === null) !== ($times[1] === null) => 'times_need_both',     // W8: an imported day is never left open
                $advance !== null && ! self::passes($advance, [...AttendanceDays::FORMAT['advance_amount'], EmployeeRules::POSITIVE]) => 'bad_amount',
                $note !== null && ! self::passes($note, AttendanceDays::FORMAT['note']) => 'note_too_long',
                default => null,
            };
            if ($employee !== null && $status !== null) {
                $seen[$r['date']][(string) $employee['id']] = true;
            }
            if ($error !== null) {
                $errors[] = self::error($at, $error);

                continue;
            }

            $type = Employment::typeOn($employee, $r['date']);
            // W6: «حاضر» is this site, or office present (no site) for office staff; any other status is its leave day-type.
            $input = ['version' => null, 'site_id' => $choice['site_id'] ?? ($type === EmployeeType::Office ? null : $site['id'])];
            // W7: an unsent time keeps what is stored, or takes the field shift on a new present day.
            if (is_string($times[0]) && is_string($times[1])) {
                $input['in_time'] = $times[0];
                $input['out_time'] = $times[1];
                // W11: HR's confirm approves the overtime the times give, even over a site manager's day stored with 0.
                if (in_array($type, [EmployeeType::Engineer, EmployeeType::DayLaborer], true) && $input['site_id'] === $site['id'] && $times[1] >= $times[0]) {
                    $shift = EffectiveDays::fieldShift($employee, $r['date'], $settings);
                    $input['ot_minutes'] = DayRules::workerMinutes($shift['start'], $shift['end'], $times[0], $times[1])['ot'];
                }
            }
            if ($advance !== null) {
                $input['advance_amount'] = Decimal::of($advance, 'advance_amount');
            }
            if ($note !== null) {
                $input['note'] = $note;
            }
            $writes[] = ['at' => $at, 'employee' => $employee, 'date' => $r['date'], 'type' => $type, 'input' => $input];
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

        return [
            'site' => ['id' => $site['id'], 'name_ar' => $site['name_ar'], 'name_en' => $site['name_en']],
            'from' => $from,
            'to' => $to,
            'rows' => count($rows),
            'token' => hash('sha256', implode("\n", $reads)),
            'writes' => $planned,
            'errors' => $errors,
        ];
    }

    /**
     * W12, W13, W15: each planned row written as HR, each in its own savepoint, then read back; the caller owns the transaction.
     * $choices (keep or replace by conflict key) is the confirm's; null is the preview, which replaces every conflict. `conflicts`
     * counts the conflict rows in `changes`, the ones the screen asks about; `advances_total` sums the rows saved or unchanged.
     *
     * @param  Plan  $plan
     * @param  array<string, string>|null  $choices
     * @return array{changes: list<array<string, mixed>>, unchanged: int, kept: int, conflicts: int, errors: list<RowError>, advances_total: string}
     */
    private function apply(array $plan, ?array $choices): array
    {
        [, $names] = $this->import->choices(EmployeeType::Office);
        $present = (string) __('attendance.template.present');
        $changes = [];
        $errors = $plan['errors'];
        $unchanged = 0;
        $kept = 0;
        $conflicts = 0;
        $total = bcadd('0', '0', Precision::MONEY_SCALE);
        foreach ($plan['writes'] as $w) {
            // W13: a conflict is written only on `replace`; `keep` writes nothing; no choice is refused. Other rows ignore $choices.
            $choice = $choices === null || ! $w['conflict'] ? 'replace' : ($choices[$w['key']] ?? null);
            if ($choice !== 'replace') {
                if ($choice === 'keep') {
                    $kept++;
                } else {
                    $errors[] = self::error($w['at'], 'choice_required');
                }

                continue;
            }
            try {
                $this->writes->writeCell($w['employee'], $w['date'], $w['input'], null, 'imported');
            } catch (AttendanceRefused|ConcurrencyConflict|DayRulesViolated $e) {
                $errors[] = [...$w['at'], ...AttendanceImport::reason($e)];

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
            $changes[] = [
                'key' => $w['key'], 'sheet' => $w['at']['sheet'], 'row' => $w['at']['row'], 'date' => $w['date'],
                'employee_id' => (string) $w['employee']['id'], 'code' => (int) $w['employee']['code'], 'name_ar' => $w['employee']['name_ar'],
                'name_en' => $w['employee']['name_en'], 'type' => $w['type']?->value, 'new_laborer' => false, 'matched_by_national_id' => false,
                'conflict_site' => $w['conflict'] ? ($from['site'] ?? null) : null,
                'from' => $from,
                'to' => self::day($after, $names, $present),
            ];
        }
        $inSheetOrder = fn (array $a, array $b) => [$a['date'], $a['row']] <=> [$b['date'], $b['row']];
        usort($changes, $inSheetOrder);
        usort($errors, $inSheetOrder);

        return ['changes' => $changes, 'unchanged' => $unchanged, 'kept' => $kept, 'conflicts' => $conflicts, 'errors' => $errors, 'advances_total' => $total];
    }

    /**
     * W2: the site and range from `lists` D1:D3, then each visible day sheet's date and header row; the data rows by date, then row.
     *
     * @return array{array{id: string, name_ar: string, name_en: ?string, managers: list<array{id: string, name: string}>}, string, string, list<array{sheet: string, row: int, date: string, cells: list<?string>}>}
     */
    private function read(string $path): array
    {
        $sheets = $this->reader->sheets($path, $this->maxRows, [2, 3]);    // C and D: the arrival and departure (W8)
        $lists = array_column($sheets, 'rows', 'name')['lists'] ?? throw self::mismatch();
        [$siteId, $from, $to] = [$lists[1][3] ?? null, $lists[2][3] ?? null, $lists[3][3] ?? null];
        // Str::isUuid first: Postgres answers a malformed uuid with a query error.
        if ($siteId === null || ! Str::isUuid($siteId) || ! self::isDate($from) || ! self::isDate($to) || $from > $to
            || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= SiteWorkbookTemplate::MAX_DAYS) {
            throw self::mismatch();
        }
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
        foreach (SiteWorkbookTemplate::dates($from, $to) as $d) {
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
                if ($n > 2) {
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

    /** W8: `H:MM` or `HH:MM` (the reader turns an Excel time into one) as `HH:MM`; null when blank, false when it is no time of day. */
    private static function time(?string $value): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/D', $value, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return false;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
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

    /** @phpstan-assert-if-true string $value */
    private static function isDate(?string $value): bool
    {
        $date = $value === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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
