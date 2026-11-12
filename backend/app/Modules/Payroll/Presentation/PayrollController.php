<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Presentation;

use App\Modules\Attendance\Application\AttendanceGrid;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Application\PayrollSheet;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One group's payroll for one period (design 2026-11-09, "API and screen"). The employee must be on the group's sheet for the period,
 * else 404 (PayrollSheet). There is no `.own` scope, so a route needs only its permission.
 */
final class PayrollController
{
    public function __construct(private readonly PayrollSheet $sheet, private readonly AttendanceGrid $grid) {}

    public function sheet(Request $request, string $group, string $periodStart): JsonResponse
    {
        return ApiEnvelope::single($request, $this->sheet->sheet($this->period($group, $periodStart)));
    }

    public function employee(Request $request, string $group, string $periodStart, string $employee): JsonResponse
    {
        return ApiEnvelope::single($request, $this->sheet->employee($this->period($group, $periodStart), $employee));
    }

    /** PUT semantics: a field left out is cleared. `version` is the entry's as read, null while it has none. */
    public function entry(Request $request, string $group, string $periodStart, string $employee): JsonResponse
    {
        $p = $this->period($group, $periodStart);
        $data = $request->validate(PayrollRules::entry($p->group, $request->all()));

        return ApiEnvelope::single($request, $this->sheet->saveEntry($p, $employee, $data, isset($data['version']) ? (int) $data['version'] : null));
    }

    /** `override` would name PHP's #[Override] class, which the router takes for an invokable controller. */
    public function addOverride(Request $request, string $group, string $periodStart, string $employee): JsonResponse
    {
        $p = $this->period($group, $periodStart);
        $data = $request->validate(PayrollRules::override($p->group, $request->input('component')), [], ['amount' => __('validation.attributes.value')]);

        return ApiEnvelope::single($request, $this->sheet->addOverride($p, $employee, $data['component'], $data['amount'], $data['reason']), 201);
    }

    /** Reverts the override to the computed value. */
    public function revertOverride(Request $request, string $group, string $periodStart, string $employee, string $override): JsonResponse
    {
        $p = $this->period($group, $periodStart);
        $data = $request->validate(PayrollRules::REVERT);

        return ApiEnvelope::single($request, $this->sheet->revertOverride($p, $employee, $override, $data['reason']));
    }

    /** The route's group and period start; a date that does not start one of the group's periods is a 422, as on the attendance pages. */
    private function period(string $group, string $start): Period
    {
        Validator::make(['group' => $group], ['group' => [Rule::enum(EmployeeType::class)]])->validate();

        return $this->grid->periodAt(EmployeeType::from($group), $start);
    }
}
