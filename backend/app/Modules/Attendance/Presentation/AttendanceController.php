<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\AttendanceGrid;
use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Employees\Domain\EmployeeType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AttendanceController
{
    public function __construct(
        private readonly AttendanceGrid $grid,
        private readonly EffectiveDays $days,
        private readonly AttendanceDays $writes,
    ) {}

    public function period(Request $request): JsonResponse
    {
        $q = $request->validate($this->periodRules());

        return ApiEnvelope::single($request, $this->grid->period(EmployeeType::from($q['group']), $q['period_start'] ?? null, $q['date'] ?? null));
    }

    public function grid(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->grid->grid(EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1), $q['date'] ?? null);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function missing(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->grid->missing(EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), $q['date'] ?? null);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function sheet(Request $request): JsonResponse
    {
        $q = $request->validate(['group' => ['required', Rule::enum(EmployeeType::class)], 'date' => ['required', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->grid->sheet(EmployeeType::from($q['group']), $q['date']));
    }

    public function show(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);

        return ApiEnvelope::single($request, $this->days->effectiveDay($employee, $date));
    }

    public function update(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);
        $data = $request->validate(AttendanceDays::FORMAT);

        return ApiEnvelope::single($request, $this->writes->save($employee, $date, $data));
    }

    public function destroy(Request $request, string $employee, string $date): JsonResponse
    {
        $this->assertDate($date);
        $q = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiEnvelope::single($request, $this->writes->clear($employee, $date, (int) $q['version']));
    }

    /** @return array<string, array<mixed>> */
    private function periodRules(): array
    {
        return ['group' => ['required', Rule::enum(EmployeeType::class)], 'period_start' => ['sometimes', 'date_format:Y-m-d'], 'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:period_start']];
    }

    /** The route only checks the shape; 2026-02-30 is refused here. */
    private function assertDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['date_format:Y-m-d']])->validate();
    }
}
