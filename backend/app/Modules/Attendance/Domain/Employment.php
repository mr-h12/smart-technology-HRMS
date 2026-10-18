<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;

/** Reads an EmployeeRoster row: hire_date, termination_date, types [{type, effective_from}], shifts [{effective_from, shift_start, shift_end}]. */
final class Employment
{
    /** @param  array<string, mixed>  $employee */
    public static function typeOn(array $employee, string $date): ?EmployeeType
    {
        if (($employee['hire_date'] ?? null) !== null && $employee['hire_date'] > $date) {
            return null;
        }
        if (($employee['termination_date'] ?? null) !== null && $employee['termination_date'] < $date) {
            return null;
        }
        $row = EffectiveDated::at($employee['types'], $date);

        return $row === null ? null : EmployeeType::from($row['type']);
    }

    /**
     * @param  array<string, mixed>  $employee
     * @return array{start: ?string, end: ?string}
     */
    public static function shiftOn(array $employee, string $date): array
    {
        $row = EffectiveDated::at($employee['shifts'], $date);

        return ['start' => $row['shift_start'] ?? null, 'end' => $row['shift_end'] ?? null];
    }
}
