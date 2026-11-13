<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\LeaveRequestForms;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveRequestFormController
{
    public function __construct(private readonly LeaveRequestForms $forms, private readonly ExperienceCertificates $exports) {}

    public function store(Request $request, string $leaveRequest): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'job_title' => ['required', 'string', 'max:160'],
            'notification_method' => ['nullable', 'string', 'max:160'],
            'manager_opinion' => ['nullable', 'string', 'max:360'],
            'substitute' => ['nullable', 'string', 'max:160'],
        ]);
        $fields = ['job_title' => $data['job_title']];
        foreach (['notification_method', 'manager_opinion', 'substitute'] as $field) {
            $fields[$field] = $data[$field] ?? '';
        }
        $export = $this->forms->create($leaveRequest, (string) $request->user()?->getAuthIdentifier(),
            RequestScope::ownerId($request), $data['idempotency_key'], $fields);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($export), 202);
    }

    public function index(Request $request, string $leaveRequest): JsonResponse
    {
        return ApiEnvelope::single($request, $this->forms->history($leaveRequest, RequestScope::ownerId($request), (string) $request->user()?->getAuthIdentifier()));
    }

    public function approve(Request $request, string $export): JsonResponse
    {
        abort_if(RequestScope::ownerId($request) !== null, 403);
        $signed = $this->forms->approve($export, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($signed), 202);
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        abort_if(RequestScope::ownerId($request) !== null, 403);
        $signed = $this->exports->retry($export, LeaveRequestForms::FORM, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($signed), 202);
    }
}
