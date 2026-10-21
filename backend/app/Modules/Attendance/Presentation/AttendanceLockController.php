<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceLocks;
use App\Modules\Employees\Domain\EmployeeType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AttendanceLockController
{
    public function __construct(private readonly AttendanceLocks $locks) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->periodRules(), 'confirm_missing' => ['sometimes', 'boolean']]);
        $period = $this->locks->lock(EmployeeType::from($data['group']), $data['period_start'], (bool) ($data['confirm_missing'] ?? false));

        return ApiEnvelope::single($request, $period, 201);
    }

    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->periodRules(), 'reason' => ['required', 'string', 'max:500']]);

        return ApiEnvelope::single($request, $this->locks->reopen(EmployeeType::from($data['group']), $data['period_start'], $data['reason']));
    }

    /** @return array<string, array<mixed>> */
    private function periodRules(): array
    {
        return ['group' => ['required', Rule::enum(EmployeeType::class)], 'period_start' => ['required', 'date_format:Y-m-d']];
    }
}
