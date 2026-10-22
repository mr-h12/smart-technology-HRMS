<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\Gender;
use App\Modules\Employees\Domain\PayMethod;
use Illuminate\Validation\Rule;

/** The employee validation rules, shared by the API and the master-data importer so both accept exactly the same input. */
final class EmployeeRules
{
    private const MONEY = ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/D'];

    /** @return array<string, array<mixed>> */
    public static function store(): array
    {
        return [
            ...self::identity(false),
            'type' => ['required', Rule::enum(EmployeeType::class)],
            ...self::pay(),
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function pay(): array
    {
        return [
            'salary' => self::MONEY,
            'raise' => self::MONEY,
            'insured_basic' => self::MONEY,
            'daily_wage' => self::MONEY,
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function identity(bool $patch, ?string $id = null): array
    {
        $r = fn (array $rules) => $patch ? ['sometimes', ...$rules] : $rules;
        $unique = fn (string $col) => Rule::unique('employees', $col)->ignore($id);

        return [
            'name_ar' => $r(['required', 'string']),
            'name_en' => ['nullable', 'string'],
            'fingerprint_name' => ['nullable', 'string', $unique('fingerprint_name')],
            'gender' => $r(['required', Rule::enum(Gender::class)]),
            'national_id' => ['nullable', 'digits:14', $unique('national_id')],
            'insurance_no' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'residence_id' => ['nullable', 'uuid', Rule::exists('residences', 'id')->whereNull('deleted_at')],
            'pay_method' => ['sometimes', Rule::enum(PayMethod::class)],
            'bank_account' => ['nullable', 'string'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'contract_end_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_reason' => ['nullable', 'string'],
            'hr_notes' => ['nullable', 'string'],
            'crm_user_id' => ['nullable', 'uuid'],
        ];
    }
}
