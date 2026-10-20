<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Attendance\Infrastructure\XlsxAttendanceReader;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Support\Facades\DB;

/**
 * The Excel grid import (slice 3, G1–G9, docs/calculations.md §14): each changed cell of an uploaded template goes through
 * AttendanceDays::writeCell as the Day sheet's row would. The preview runs every check and saves nothing; confirm re-reads the file
 * and writes in one transaction, a refused cell skipped and returned. $ownerId (own scope) narrows the rows to that manager's employees.
 */
final class AttendanceImport
{
    /** A technical cap (G9), not a business setting. */
    public const MAX_ROWS = 2000;

    /** The codes the import itself gives; the others are AttendanceDays' refusals and DayRules' codes. */
    private const OWN_CODES = ['unknown_employee', 'unknown_site', 'duplicate_row'];

    public function __construct(
        private readonly AttendanceGrid $periods,
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly AttendanceDays $writes,
        private readonly XlsxAttendanceReader $reader,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    /**
     * @return array{summary: array{cells: int, changes: int, unchanged: int, errors: int}, changes: list<array{row: int, column: string, employee_id: string, code: int, name_ar: string, date: string, from: ?string, to: string}>, errors: list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string}>}
     */
    public function preview(EmployeeType $g, string $start, string $path, ?string $ownerId): array
    {
        $plan = $this->plan($g, $start, $path, $ownerId);
        $changes = [];
        $errors = $plan['errors'];
        foreach ($plan['changes'] as $c) {
            $error = $this->write($c, true);
            if ($error === null) {
                $changes[] = $c['change'];
            } else {
                $errors[] = $error;
            }
        }

        return [
            'summary' => ['cells' => $plan['cells'], 'changes' => count($changes), 'unchanged' => $plan['unchanged'], 'errors' => count($errors)],
            'changes' => $changes,
            'errors' => self::inSheetOrder($errors),
        ];
    }

    /** @return array{applied: int, unchanged: int, errors: list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string}>} */
    public function confirm(EmployeeType $g, string $start, string $path, ?string $ownerId): array
    {
        $plan = $this->plan($g, $start, $path, $ownerId);
        $cells = $plan['changes'];
        // Rows are locked in (employee_id, date) order, as bulk() locks them, so an import and a bulk write cannot deadlock.
        usort($cells, fn (array $a, array $b) => strcmp($a['employee']['id'].$a['date'], $b['employee']['id'].$b['date']));
        $refused = array_values(array_filter(DB::transaction(fn () => array_map(fn (array $c) => $this->write($c, false), $cells))));

        return ['applied' => count($cells) - count($refused), 'unchanged' => $plan['unchanged'], 'errors' => self::inSheetOrder([...$plan['errors'], ...$refused])];
    }

    /**
     * Reads and checks the file (G7) and sorts its non-blank day cells into unchanged (G3), refused, and the changes to write.
     *
     * @return array{cells: int, unchanged: int, changes: list<array{employee: array<string, mixed>, date: string, index: int, input: array{version: ?int, site_id: ?string}, change: array{row: int, column: string, employee_id: string, code: int, name_ar: string, date: string, from: ?string, to: string}}>, errors: list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string, index: int}>}
     */
    private function plan(EmployeeType $g, string $start, string $path, ?string $ownerId): array
    {
        $p = $this->periods->periodAt($g, $start);
        $file = $this->reader->read($path, $this->maxRows);
        $dates = $p->days();
        $headers = [AttendanceTemplate::header($dates, 'ar'), AttendanceTemplate::header($dates, 'en')];
        if (! in_array($file['header'], $headers, true) || ! in_array($file['versions'][0], $headers, true)) {
            throw AttendanceRefused::because(AttendanceRefusal::TemplateMismatch);
        }

        $employees = [];
        foreach ($this->roster->allOfType($g, $p->start, $p->end, $ownerId) as $e) {
            $employees[(int) $e['code']] = $e;
        }
        // Matched by code, not by row, so sorting the visible sheet cannot pair a cell with another employee's version.
        $versions = [];
        foreach (array_slice($file['versions'], 1) as $cells) {
            $code = self::code($cells[0] ?? null);
            if ($code !== null) {
                $versions[$code] ??= $cells;
            }
        }
        $days = $this->days->forPeriod($p, array_values($employees));
        [$choices, $names] = $this->choices($g);
        $present = (string) __('attendance.template.present');
        $locked = PeriodGuard::isLocked($p);
        $today = EffectiveDays::today();

        $plan = ['cells' => 0, 'unchanged' => 0, 'changes' => [], 'errors' => []];
        $seen = [];
        foreach ($file['rows'] as ['row' => $row, 'cells' => $values]) {
            $code = self::code($values[0] ?? null);
            $duplicate = $code !== null && isset($seen[$code]);
            if ($code !== null) {
                $seen[$code] = true;
            }
            $filled = array_filter(array_slice($values, 2, count($dates), true), fn (?string $v) => $v !== null);
            if ($filled === []) {
                continue;   // G2: a blank cell is no change
            }
            $plan['cells'] += count($filled);
            $employee = $code === null ? null : ($employees[$code] ?? null);
            if ($employee === null || $duplicate) {
                $plan['errors'][] = self::error($row, 0, $code, null, $employee === null ? 'unknown_employee' : 'duplicate_row');

                continue;
            }

            foreach ($filled as $i => $value) {
                $date = $dates[$i - 2];
                $day = $days[$employee['id']][$date];
                $choice = $choices[self::key($value)] ?? null;
                if ($date > $today) {
                    $plan['errors'][] = self::error($row, $i, $code, $date, 'future_date');
                } elseif ($day['state'] === 'not_employed') {     // also a day employed as another type (G5)
                    $plan['errors'][] = self::error($row, $i, $code, $date, 'employee_not_active');
                } elseif ($choice !== null && self::same($choice, $day)) {
                    $plan['unchanged']++;
                } elseif ($locked || $choice === null) {         // every changed cell of a locked period
                    $plan['errors'][] = self::error($row, $i, $code, $date, $locked ? 'period_locked' : 'unknown_site');
                } else {
                    $from = $day['site_id'] !== null ? ($names[$day['site_id']] ?? null) : ($day['state'] === 'stored' ? $present : null);
                    $plan['changes'][] = [
                        'employee' => $employee, 'date' => $date, 'index' => $i,
                        // The Day sheet's bulk row (G1); a blank version expects no stored day (G4).
                        'input' => ['version' => self::version($versions[$code][$i] ?? null), 'site_id' => $choice['site_id']],
                        'change' => [
                            'row' => $row, 'column' => self::column($i), 'employee_id' => (string) $employee['id'], 'code' => $code,
                            'name_ar' => (string) $employee['name_ar'], 'date' => $date, 'from' => $from, 'to' => $choice['name'],
                        ],
                    ];
                }
            }
        }

        return $plan;
    }

    /**
     * @param  array{employee: array<string, mixed>, date: string, index: int, input: array{version: ?int, site_id: ?string}, change: array{row: int, column: string, employee_id: string, code: int, name_ar: string, date: string, from: ?string, to: string}}  $c
     * @return array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string, index: int}|null the shared write's refusal, with its own code
     */
    private function write(array $c, bool $dryRun): ?array
    {
        $at = [$c['change']['row'], $c['index'], $c['change']['code'], $c['date']];
        try {
            $this->writes->writeCell($c['employee'], $c['date'], $c['input'], 'imported', $dryRun);

            return null;
        } catch (AttendanceRefused $e) {
            return self::error(...$at, error: $e->reason->value, message: (string) __('attendance.refusal.'.$e->reason->value, $e->params));
        } catch (DayRulesViolated $e) {
            $code = $e->violations[0]['code'];

            return self::error(...$at, error: $code, message: (string) __('attendance.rule.'.$code));
        }
    }

    /**
     * What a cell may say for the group (G6), keyed by self::key of a name_ar or name_en; office takes the present word in either
     * language. Inactive sites are matched too, so the write refuses them (site_inactive); a deleted one is unknown.
     *
     * @return array{array<string, array{site_id: ?string, leave_code: ?string, name: string}>, array<array-key, string>} the choices, and every site's name (by id) in the request language
     */
    private function choices(EmployeeType $g): array
    {
        $en = app()->getLocale() === 'en';
        $sites = Site::withTrashed()->orderByDesc('active')->get(['id', 'name_ar', 'name_en', 'kind', 'leave_code', 'active', 'deleted_at']);
        $names = [];
        foreach ($sites as $s) {
            $names[(string) $s->id] = (string) ($en ? ($s->name_en ?? $s->name_ar) : $s->name_ar);
        }

        $choices = [];
        if ($g === EmployeeType::Office) {
            foreach (['ar', 'en'] as $locale) {
                $choices[self::key((string) __('attendance.template.present', [], $locale))] = ['site_id' => null, 'leave_code' => null, 'name' => (string) __('attendance.template.present')];
            }
        }
        foreach ($sites as $s) {
            if ($s->trashed() || ($g === EmployeeType::Office && $s->kind !== 'leave')) {
                continue;
            }
            foreach ([$s->name_ar, $s->name_en] as $name) {
                if ($name !== null) {
                    $choices[self::key((string) $name)] ??= ['site_id' => (string) $s->id, 'leave_code' => $s->leave_code, 'name' => $names[(string) $s->id]];
                }
            }
        }

        return [$choices, $names];
    }

    /**
     * G3: the cell names the stored day's site, or a derived Friday or holiday's leave type.
     *
     * @param  array{site_id: ?string, leave_code: ?string, name: string}  $choice
     * @param  array<string, mixed>  $day  an EffectiveDays day
     */
    private static function same(array $choice, array $day): bool
    {
        return ($day['state'] === 'stored' && $day['site_id'] === $choice['site_id'])
            || ($day['state'] === 'derived' && $choice['leave_code'] !== null && $day['leave_code'] === $choice['leave_code']);
    }

    /** @return array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string, index: int} a row error when $index is 0 (the code column) */
    private static function error(int $row, int $index, ?int $code, ?string $date, string $error, ?string $message = null): array
    {
        $key = (in_array($error, self::OWN_CODES, true) ? 'attendance.import.' : 'attendance.refusal.').$error;

        return ['row' => $row, 'column' => $index === 0 ? null : self::column($index), 'code' => $code, 'date' => $date, 'error' => $error, 'message' => $message ?? (string) __($key), 'index' => $index];
    }

    /**
     * @param  list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string, index: int}>  $errors
     * @return list<array{row: int, column: ?string, code: ?int, date: ?string, error: string, message: string}>
     */
    private static function inSheetOrder(array $errors): array
    {
        usort($errors, fn (array $a, array $b) => [$a['row'], $a['index']] <=> [$b['row'], $b['index']]);

        return array_map(function (array $e): array {
            unset($e['index']);

            return $e;
        }, $errors);
    }

    /** The Excel column letter of a 0-based column index (G8). */
    private static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(XlsxAttendanceReader::text($name) ?? '');
    }

    private static function code(?string $cell): ?int
    {
        return $cell !== null && ctype_digit($cell) ? (int) $cell : null;
    }

    /** A version that is not a number is 0, which no stored day has, so the cell is a conflict. */
    private static function version(?string $cell): ?int
    {
        return $cell === null ? null : (ctype_digit($cell) ? (int) $cell : 0);
    }
}
