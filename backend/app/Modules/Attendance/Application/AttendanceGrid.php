<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceLock;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The period header, the HR grid and the missing days of one group's period. */
final class AttendanceGrid
{
    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days) {}

    /** @return array{group: string, start: string, end: string, prev_start: string, next_start: ?string, is_locked: bool, lock: ?array<string, mixed>, missing: int} */
    public function period(EmployeeType $g, ?string $start): array
    {
        $p = $this->periodAt($g, $start);
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
    public function grid(EmployeeType $g, ?string $start, int $perPage, int $page): array
    {
        $p = $this->periodAt($g, $start);
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
    public function missing(EmployeeType $g, ?string $start, int $perPage): array
    {
        $all = $this->missingDays($this->periodAt($g, $start));
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

    public function periodAt(EmployeeType $g, ?string $start): Period
    {
        if ($start === null) {
            return Period::containing($g, EffectiveDays::today());
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
