<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The period header, the HR grid, the day sheet and the missing days of one group's period. */
final class AttendanceGrid
{
    /** The last site looks this many days back from the sheet date (P31). */
    public const LAST_SITE_DAYS = 7;

    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days, private readonly BusinessRules $rules) {}

    /** @return array{group: string, start: string, end: string, prev_start: string, next_start: ?string, is_locked: bool, lock: ?array<string, mixed>, missing: int} */
    public function period(EmployeeType $g, ?string $start, ?string $date = null): array
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
            'missing' => count($this->missingDays($p)),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function grid(EmployeeType $g, ?string $start, int $perPage, int $page, ?string $date = null): array
    {
        $p = $this->periodAt($g, $start, $date);
        $roster = $this->roster->ofType($g, $p->start, $p->end, $perPage, $page);
        $days = $this->days->forPeriod($p, $roster['data']);

        return [
            'data' => array_map(fn (array $e) => [
                'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en']],
                'days' => array_values(array_map(
                    fn (array $d) => ['date' => $d['date'], 'state' => $d['state'], 'source' => $d['source'], 'site_id' => $d['site_id'], 'leave_code' => $d['leave_code']],
                    $days[$e['id']],
                )),
            ], $roster['data']),
            'pagination' => $roster['pagination'],
        ];
    }

    /** @return array{data: list<array{employee_id: string, code: int, name_ar: string, name_en: ?string, date: string}>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function missing(EmployeeType $g, ?string $start, int $perPage, ?string $date = null): array
    {
        $all = $this->missingDays($this->periodAt($g, $start, $date));
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
     * One group's employees on one date (those employed then, by code), with the day, its hints, the last site and the period totals,
     * plus the period's missing days per date.
     *
     * @return array{group: string, date: string, days: list<array{date: string, missing: int}>, rows: list<array<string, mixed>>}
     */
    public function sheet(EmployeeType $g, string $date): array
    {
        $today = EffectiveDays::today();
        if ($date > $today) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        // ponytail: whole group in PHP (~1,000 × 31 cells); move counts to SQL if a group outgrows it
        $p = Period::containing($g, $date);
        $employees = $this->roster->allOfType($g, $p->start, $p->end);
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
            $rows[] = [
                'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'department' => $e['department']],
                'day' => array_intersect_key($day, array_flip(['state', 'source', 'version', 'site_id', 'leave_code', ...EffectiveDays::VALUES])),
                'hints' => (object) $this->days->hints($g, $e, $day, $settings),
                'last_site_id' => $lastSites[$e['id']] ?? null,
                'totals' => $totals,
            ];
        }

        return [
            'group' => $g->value,
            'date' => $date,
            'days' => array_map(fn (string $d, int $n) => ['date' => $d, 'missing' => $n], array_keys($missing), array_values($missing)),
            'rows' => $rows,
        ];
    }

    /** @return list<array{group: string, period_start: string, period_end: string, missing: int}> per group, the open periods among the one containing $date and the one before, counted up to $date */
    public function missingCounts(string $date): array
    {
        $counts = [];
        foreach (EmployeeType::cases() as $g) {
            $current = Period::containing($g, $date);
            foreach ([$current->previous(), $current] as $p) {
                if (! PeriodGuard::isLocked($p)) {
                    $missing = array_filter($this->missingDays($p), fn (array $d) => $d['date'] <= $date);
                    $counts[] = ['group' => $g->value, 'period_start' => $p->start, 'period_end' => $p->end, 'missing' => count($missing)];
                }
            }
        }

        return $counts;
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

    /** @return list<array{employee_id: string, code: int, name_ar: string, name_en: ?string, date: string}> by code then date */
    private function missingDays(Period $p): array
    {
        // ponytail: in-PHP count over ~100 employees × 31 days; move to SQL if a group grows past ~1,000
        $employees = $this->roster->allOfType($p->group, $p->start, $p->end);
        $days = $this->days->forPeriod($p, $employees);
        $missing = [];
        foreach ($employees as $e) {
            foreach ($days[$e['id']] as $d) {
                if ($d['state'] === 'missing') {
                    $missing[] = ['employee_id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'date' => $d['date']];
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
