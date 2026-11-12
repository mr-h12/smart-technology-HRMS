<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

/** Asked before a termination date is saved: each listener returns the employee's open items it owns (Leave: open requests), so Employees never depends on them. */
final class EmployeeOpenItems
{
    public function __construct(public readonly string $employeeId) {}
}
