<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Pdf\Application\PdfExports;
use App\Modules\Pdf\Domain\PdfType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveRequestFormController
{
    public function __construct(private readonly PdfExports $exports) {}

    public function store(Request $request, string $leaveRequest): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid'], ...PdfExports::document(PdfType::LeaveRequest)->rules()]);
        $fields = ['job_title' => $data['job_title']];
        foreach (['notification_method', 'manager_opinion', 'substitute'] as $field) {
            $fields[$field] = $data[$field] ?? '';
        }
        $export = $this->exports->create(PdfType::LeaveRequest, $leaveRequest, $data['idempotency_key'], $fields,
            $request->user() ?? throw new AuthenticationException, RequestScope::ownerId($request));

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($export), 202);
    }

    public function index(Request $request, string $leaveRequest): JsonResponse
    {
        return ApiEnvelope::single($request, $this->exports->leaveHistory($leaveRequest, RequestScope::ownerId($request), (string) $request->user()?->getAuthIdentifier()));
    }

    public function approve(Request $request, string $export): JsonResponse
    {
        abort_if(RequestScope::ownerId($request) !== null, 403);
        abort_unless($this->exports->find($export)->form_type === PdfType::LeaveRequest->value, 404);
        $signed = $this->exports->approve(PdfType::LeaveRequest, $export, $request->user() ?? throw new AuthenticationException);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($signed), 202);
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        abort_if(RequestScope::ownerId($request) !== null, 403);
        $signed = $this->exports->retry(PdfType::LeaveRequest, $export, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($signed), 202);
    }
}
