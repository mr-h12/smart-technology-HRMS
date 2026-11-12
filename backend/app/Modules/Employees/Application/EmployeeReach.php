<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a manager reaches on a date D, by the employee's type on D (design Part 1): an engineer assigned to them on D, or a worker whose
 * site on D (that day's crew change, else the home site) is one of their sites (P1: manager_sites is undated). Office staff are in no manager's reach (P2).
 * An archived employee is in no one's reach, whatever the date (owner, 2026-11-11). Nor is the site manager's own employee record: HR
 * records their attendance (owner, 2026-11-12).
 */
final class EmployeeReach
{
    private const ENGINEER = '(select m.manager_id from employee_manager_assignments m where m.employee_id = employees.id and m.deleted_at is null and m.effective_from <= ?::date order by m.effective_from desc limit 1) = ?';

    /** The worker's site on D: that day's crew change, else the home site (Employee::SITE_ON). */
    private const WORKER = 'exists (select 1 from manager_sites ms where ms.manager_id = ? and ms.site_id = '.Employee::SITE_ON.')';

    /**
     * @param  Builder<Employee>  $employees
     * @return Builder<Employee>
     */
    public static function apply(Builder $employees, string $managerId, string $date): Builder
    {
        // ponytail: correlated subqueries; a reach view if manager lists pass ~1,000
        return self::exceptOwn($employees->archived(false), $managerId)->whereRaw(
            'case '.Employee::TYPE_ON.' when ? then '.self::ENGINEER.' when ? then '.self::WORKER.' when ? then '.self::WORKER.' else false end',
            [
                $date,
                EmployeeType::Engineer->value, $date, $managerId,
                EmployeeType::PermanentWorker->value, $managerId, $date, $date, $date,
                EmployeeType::DayLaborer->value, $managerId, $date, $date, $date,
            ],
        );
    }

    /**
     * Leaves out the employee linked to $userId's account, for the searches that look past the reach (the crew search).
     *
     * @param  Builder<Employee>  $employees
     * @return Builder<Employee>
     */
    public static function exceptOwn(Builder $employees, string $userId): Builder
    {
        return $employees->whereRaw('employees.user_id is distinct from ?', [$userId]);
    }

    /** @return Builder<Employee> the ids of the employees $managerId reaches on $date, as a whereIn subquery */
    public static function ids(string $managerId, string $date): Builder
    {
        return self::apply(Employee::query()->select('employees.id'), $managerId, $date);
    }

    public static function reaches(string $employeeId, string $managerId, string $date): bool
    {
        return self::apply(Employee::query()->whereKey($employeeId), $managerId, $date)->exists();
    }
}
