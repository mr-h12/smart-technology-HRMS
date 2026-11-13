<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceRequests;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceRequestController
{
    public function __construct(private readonly AttendanceRequests $requests) {}

    public function index(Request $request): JsonResponse
    {
        self::requireAll($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,refused,all'],
            'type' => ['sometimes', 'in:overtime,advance'],
            'site_id' => ['sometimes', 'uuid'],
            'employee_id' => ['sometimes', 'uuid'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->requests->list($filters);

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        self::requireAll($request);
        return ApiEnvelope::single($request, $this->requests->show($id));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, true);
    }

    public function refuse(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, string $id, bool $approve): JsonResponse
    {
        self::requireAll($request);
        $q = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'paper_decision_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => $approve ? ['prohibited'] : ['required', 'string', 'max:500'],
        ]);
        $status = $this->requests->decide($id, (int) $q['version'], $approve, $q['reason'] ?? null, (string) $request->user()?->getAuthIdentifier(),
            $q['paper_decision_date'] ?? null, array_key_exists('paper_decision_date', $q));

        return ApiEnvelope::single($request, ['id' => $id, 'status' => $status]);
    }

    private static function requireAll(Request $request): void
    {
        if (RequestScope::ownerId($request) !== null) {
            throw AuthorizationRefused::of('attendance_request.decide');
        }
    }
}
