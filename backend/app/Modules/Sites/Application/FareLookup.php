<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Domain\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare as FareRow;

/** The daily fare an employee gets for a site on a date: their override, else residence x site, else none. */
final class FareLookup
{
    public function for(string $employeeId, string $siteId, string $date): string
    {
        return $this->describe($employeeId, $siteId, $date)['amount'];
    }

    /** @return array{amount: string, source: 'override'|'default'|'none'} */
    public function describe(string $employeeId, string $siteId, string $date): array
    {
        $employee = Employee::findOrFail($employeeId);

        $override = EmployeeFareOverride::where('employee_id', $employeeId)->where('site_id', $siteId)
            ->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first()?->amount;
        $default = $employee->residence_id === null ? null : FareRow::where('residence_id', $employee->residence_id)
            ->where('site_id', $siteId)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first()?->amount;

        return [
            'amount' => Fare::resolve($override, $default),
            'source' => $override !== null ? 'override' : ($default !== null ? 'default' : 'none'),
        ];
    }
}
