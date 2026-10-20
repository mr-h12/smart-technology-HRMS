<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\AttendanceGrid;
use App\Modules\Attendance\Application\AttendanceTemplate;
use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AttendanceController
{
    public function __construct(
        private readonly AttendanceGrid $grid,
        private readonly EffectiveDays $days,
        private readonly AttendanceDays $writes,
        private readonly EmployeeService $employees,
        private readonly AttendanceTemplate $template,
    ) {}

    public function period(Request $request): JsonResponse
    {
        $q = $request->validate($this->periodRules());

        return ApiEnvelope::single($request, $this->grid->period(EmployeeType::from($q['group']), $q['period_start'] ?? null, $q['date'] ?? null, RequestScope::ownerId($request)));
    }

    public function grid(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->grid->grid(EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1), $q['date'] ?? null, RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function missing(Request $request): JsonResponse
    {
        $q = $request->validate([...$this->periodRules(), 'per_page' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $r = $this->grid->missing(EmployeeType::from($q['group']), $q['period_start'] ?? null, (int) ($q['per_page'] ?? 20), $q['date'] ?? null, RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function sheet(Request $request): JsonResponse
    {
        $q = $request->validate(['group' => ['required', Rule::enum(EmployeeType::class)], 'date' => ['required', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->grid->sheet(EmployeeType::from($q['group']), $q['date'], RequestScope::ownerId($request)));
    }

    /** The Excel grid template of one period; own scope narrows it to the caller's employees, as the sheet. */
    public function template(Request $request): BinaryFileResponse
    {
        $q = $request->validate(['group' => ['required', Rule::enum(EmployeeType::class)], 'period_start' => ['required', 'date_format:Y-m-d']]);
        $path = $this->template->file(EmployeeType::from($q['group']), $q['period_start'], RequestScope::ownerId($request));

        return response()->download($path, "attendance-{$q['group']}-{$q['period_start']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
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

    /** An own-scope user reaches only employees assigned to them today, as `employee.scope` does; 404 so ids are not confirmed. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(AttendanceDays::BULK_FORMAT);
        $ownerId = RequestScope::ownerId($request);
        if ($ownerId !== null) {
            foreach ($data['rows'] as $row) {
                if (! $this->employees->isManagedBy($row['employee_id'], $ownerId)) {
                    throw new ModelNotFoundException;
                }
            }
        }

        return ApiEnvelope::single($request, $this->writes->bulk($data['date'], $data['rows']));
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
