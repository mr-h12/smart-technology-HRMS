<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Application\Signoffs;
use App\Modules\Employees\Domain\EmployeeType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SignoffController
{
    public function __construct(private readonly Signoffs $signoffs) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'which' => ['sometimes', 'in:current,previous'], 'at' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.EffectiveDays::today()]]);

        return ApiEnvelope::single($request, $this->signoffs->forSite($q['site_id'], $q['which'] ?? 'current', (string) $request->user()?->getAuthIdentifier(), $q['at'] ?? null));
    }

    public function sign(Request $request): JsonResponse
    {
        $q = $request->validate([
            'site_id' => ['required', 'uuid'], 'employee_id' => ['required', 'uuid'], 'employee_type' => ['required', Rule::enum(EmployeeType::class)],
            'period_start' => ['required', 'date_format:Y-m-d'], 'version' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        return ApiEnvelope::single($request, $this->signoffs->sign(
            $q['site_id'], $q['employee_id'], EmployeeType::from($q['employee_type']), $q['period_start'],
            $q['version'] === null ? null : (int) $q['version'], (string) $request->user()?->getAuthIdentifier(),
        ), 201);
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $q = $request->validate(['version' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:500']]);

        return ApiEnvelope::single($request, $this->signoffs->returnToManager($id, (int) $q['version'], $q['note'], (string) $request->user()?->getAuthIdentifier()));
    }
}
