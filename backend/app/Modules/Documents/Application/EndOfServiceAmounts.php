<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class EndOfServiceAmounts
{
    /** @param array<string, mixed> $employee
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function calculate(array $employee, array $fields): array
    {
        $hired = $employee['hire_date'] ?? null;
        $lastDay = (string) $fields['last_working_day'];
        if (! is_string($hired) || $lastDay < $hired || $lastDay > now('Africa/Cairo')->addYear()->toDateString()) {
            throw ValidationException::withMessages(['last_working_day' => __('validation.end_of_service_last_day')]);
        }
        if ((string) $fields['wage_month'] > substr($lastDay, 0, 7)) {
            throw ValidationException::withMessages(['wage_month' => __('validation.end_of_service_wage_month')]);
        }
        $wage = (string) $fields['last_total_wage'];
        if (! is_numeric($wage) || bccomp($wage, '0', 6) <= 0) {
            throw ValidationException::withMessages(['last_total_wage' => __('validation.end_of_service_wage')]);
        }

        $hireDate = new DateTimeImmutable($hired);
        $endDate = new DateTimeImmutable($lastDay);
        $years = $hireDate->diff($endDate)->y;
        $suggested = bcdiv(bcmul($wage, (string) $years, 6), '2', 6);
        $override = $fields['final_amount'];
        if ($override !== null && empty(trim((string) $fields['override_reason']))) {
            throw ValidationException::withMessages(['override_reason' => __('validation.required', ['attribute' => __('validation.attributes.override_reason')])]);
        }

        return [
            ...$fields,
            'hire_date' => $hired,
            'completed_years' => $years,
            'suggested_amount' => $suggested,
            'submitted_amount' => $override,
            'final_amount' => $override ?? $suggested,
            'override_reason' => $override === null ? null : $fields['override_reason'],
        ];
    }
}
