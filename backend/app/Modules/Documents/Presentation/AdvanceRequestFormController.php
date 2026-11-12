<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\AdvanceRequestForms;
use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdvanceRequestFormController
{
    public function __construct(
        private readonly AdvanceRequestForms $forms,
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
    ) {}

    public function store(Request $request, string $id): JsonResponse
    {
        abort_unless($this->forms->canViewPay($request->user()), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'job_title' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:300'],
            'repayment_method' => ['required', 'in:receipts,cash,salary_deduction'],
            'previous_advance' => ['required', 'boolean'],
            'finance_remarks' => ['nullable', 'string', 'max:300'],
            'paper_decision_date' => ['missing'],
        ], ['paper_decision_date.missing' => __('errors.advance_paper_date_pending')]);
        $export = $this->forms->create($id, (string) $request->user()?->getAuthIdentifier(), $data['idempotency_key'], [
            'job_title' => $data['job_title'], 'reason' => $data['reason'], 'repayment_method' => $data['repayment_method'],
            'previous_advance' => (bool) $data['previous_advance'], 'finance_remarks' => $data['finance_remarks'] ?? '',
        ], RequestScope::ownerId($request));

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($export), 202);
    }

    public function index(Request $request, string $id): JsonResponse
    {
        abort_unless($this->forms->canViewPay($request->user()), 403);

        return ApiEnvelope::single($request, ['exports' => $this->forms->history($id, RequestScope::ownerId($request))]);
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        abort_unless($this->forms->canViewPay($request->user()), 403);
        $row = $this->exports->find($export);
        abort_unless($row->form_type === AdvanceRequestForms::FORM, 404);
        $scope = RequestScope::ownerId($request);
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $row->employee_id, $scope), 404);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($this->exports->retry($export, AdvanceRequestForms::FORM)), 202);
    }
}
