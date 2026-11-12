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
    private const AMOUNT = '\d{1,12}(\.\d{1,6})?';

    /** An amount as the API takes it: up to 12 digits and 6 decimals, as text so no float touches it. */
    public const MONEY = 'regex:/^'.self::AMOUNT.'$/D';

    /** The same, below zero too (a payroll net override). */
    public const SIGNED_MONEY = 'regex:/^-?'.self::AMOUNT.'$/D';

    /** A wage that must be above zero. */
    public const POSITIVE = 'not_regex:/^0+(\.0+)?$/D';

    /** An Egyptian mobile number, kept as text so its leading 0 survives (owner, 2026-11-03). */
    public const MOBILE = 'regex:/^01[0125]\d{8}$/D';

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
            'salary' => ['nullable', self::MONEY],
            'raise' => ['nullable', self::MONEY],
            'insured_basic' => ['nullable', self::MONEY],
            'daily_wage' => ['nullable', self::MONEY],
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
            'phone' => ['nullable', 'string', self::MOBILE],
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
