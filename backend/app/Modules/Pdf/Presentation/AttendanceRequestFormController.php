<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Pdf\Application\PdfExports;
use App\Modules\Pdf\Domain\PdfType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceRequestFormController
{
    public function __construct(private readonly PdfExports $exports, private readonly EmployeeService $employees) {}

    public function store(Request $request, string $id, string $type = 'advance'): JsonResponse
    {
        $form = PdfType::from($type.'_request');
        $advance = $form === PdfType::AdvanceRequest;
        abort_if($advance && ! $this->exports->canViewPay($request->user()), 403);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid'], ...PdfExports::document($form)->rules()],
            ['paper_decision_date.missing' => __('errors.advance_paper_date_pending')]);
        $fields = ['job_title' => $data['job_title'], 'reason' => $data['reason']];
        if ($advance) {
            $fields += ['repayment_method' => $data['repayment_method'], 'previous_advance' => (bool) $data['previous_advance'],
                'finance_remarks' => $data['finance_remarks'] ?? ''];
        }
        $export = $this->exports->create($form, $id, $data['idempotency_key'], $fields,
            $request->user() ?? throw new AuthenticationException, RequestScope::ownerId($request));

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($export), 202);
    }

    public function index(Request $request, string $id): JsonResponse
    {
        return ApiEnvelope::single($request, ['exports' => $this->exports->attendanceHistory($id, RequestScope::ownerId($request), $request->user())]);
    }

    public function retry(Request $request, string $export, string $type = 'advance'): JsonResponse
    {
        $form = PdfType::from($type.'_request');
        abort_if($form === PdfType::AdvanceRequest && ! $this->exports->canViewPay($request->user()), 403);
        $row = $this->exports->find($export);
        abort_unless($row->form_type === $form->value, 404);
        $scope = RequestScope::ownerId($request);
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $row->employee_id, $scope), 404);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($this->exports->retry($form, $export)), 202);
    }
}
