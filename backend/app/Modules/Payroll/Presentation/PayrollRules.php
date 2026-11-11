<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Presentation;

use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Application\PayrollSheet;
use App\Modules\Payroll\Domain\Component;
use Closure;
use Illuminate\Validation\Rule;

/** The payroll API's body rules: HR's entry per type and the override. Format only; PayrollSheet checks the rest. */
final class PayrollRules
{
    /**
     * The entry fields each type uses besides `note` (design 2026-11-09, "Data (new tables)"). Office has negligence and no deduction:
     * its net has none (calculations §1). Everything else is refused, never silently dropped.
     */
    private const ENTRY_FIELDS = [
        'office' => ['bonus', 'negligence', 'lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes', 'advances', 'insurance_employee', 'insurance_company', 'income_tax'],
        'engineer' => ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
        'permanent_worker' => ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
        'day_laborer' => ['bonus', 'deduction', 'deduction_reason'],
    ];

    private const TEXT = ['nullable', 'string', 'max:500'];

    private const REASON = ['required', 'string', 'max:500'];

    public const REVERT = ['reason' => self::REASON];

    /**
     * The entry body of a type. Blank is cleared; a non-zero deduction needs a reason.
     *
     * @param  array<array-key, mixed>  $input  the request body, so that an unknown key is refused too
     * @return array<array-key, array<mixed>>
     */
    public static function entry(EmployeeType $group, array $input): array
    {
        // A field the type does not use, or an unknown key, takes null only (`prohibited` would also let [] through).
        $notUsed = fn (string $attribute, mixed $value, Closure $fail) => $fail(__('errors.payroll_field_not_used', ['type' => __('errors.employee_type.'.$group->value)]));
        $rules = array_fill_keys(array_keys($input), ['nullable', $notUsed]);
        $rules['version'] = ['nullable', 'integer', 'min:1'];
        foreach ([...self::ENTRY_FIELDS[$group->value], 'note'] as $field) {
            $rules[$field] = match (true) {
                in_array($field, PayrollSheet::AMOUNTS, true) => ['nullable', 'string', EmployeeRules::MONEY],
                // JSON integers; the cap is the column's range.
                in_array($field, PayrollSheet::COUNTS, true) => ['nullable', 'integer:strict', 'min:0', 'max:2147483647'],
                default => self::TEXT,
            };
        }
        if (in_array('deduction_reason', self::ENTRY_FIELDS[$group->value], true)) {
            $deduction = $input['deduction'] ?? null;
            $rules['deduction_reason'][] = Rule::requiredIf(is_string($deduction) && preg_match('/[1-9]/', $deduction) === 1);
        }

        return $rules;
    }

    /**
     * Any Component value; PayrollSheet refuses one the type's line does not carry.
     *
     * @return array<string, array<mixed>>
     */
    public static function override(mixed $component): array
    {
        return [
            'component' => ['required', Rule::enum(Component::class)],
            // The net alone may be negative: every other component is a magnitude its sign adds or deducts (Component::sign).
            'amount' => ['required', 'string', $component === Component::Net->value ? EmployeeRules::SIGNED_MONEY : EmployeeRules::MONEY],
            'reason' => self::REASON,
        ];
    }
}
