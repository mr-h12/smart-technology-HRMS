<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\AttendanceRequestForms;
use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\ManualFormDefinitions;
use App\Modules\Documents\Application\ManualHrForms;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManualHrFormController
{
    public function __construct(
        private readonly ManualHrForms $forms,
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
        private readonly AttendanceRequestForms $pay,
    ) {}

    public function definitions(Request $request): JsonResponse
    {
        $candidate = $request->boolean('candidate');
        abort_if($candidate && (! $this->pay->canViewPay($request->user()) || RequestScope::ownerId($request) !== null), 403);
        $forms = [];
        foreach (ManualFormDefinitions::all() as $type => $definition) {
            if (($definition['candidate'] ?? false) !== $candidate
                || (ManualFormDefinitions::showsPay($type) && ! $this->pay->canViewPay($request->user()))) {
                continue;
            }
            $fields = [];
            foreach ($definition['fields'] as $name => $field) {
                $max = collect($field['rules'])->first(fn (string $rule): bool => str_starts_with($rule, 'max:'));
                $fields[] = [
                    'name' => $name, 'input' => $field['input'],
                    'required' => in_array('required', $field['rules'], true),
                    'max' => $max === null ? null : (int) substr($max, 4),
                ];
            }
            $forms[] = ['type' => $type, 'fields' => $fields];
        }

        return ApiEnvelope::single($request, ['forms' => $forms]);
    }

    public function store(Request $request, string $employee, string $form): JsonResponse
    {
        return $this->generate($request, $form, $employee);
    }

    public function storeCandidate(Request $request, string $form): JsonResponse
    {
        $this->requireCandidateAccess($request);

        return $this->generate($request, $form, null);
    }

    public function candidates(Request $request, string $form): JsonResponse
    {
        $this->requireCandidateAccess($request);

        return ExperienceCertificateController::list($request, DB::table('hr_form_exports')->where('form_type', $form)
            ->whereNull('employee_id')->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id')->paginate(25));
    }

    private function requireCandidateAccess(Request $request): void
    {
        abort_if(! $this->pay->canViewPay($request->user()) || RequestScope::ownerId($request) !== null, 403);
    }

    private function generate(Request $request, string $form, ?string $employee): JsonResponse
    {
        abort_if(ManualFormDefinitions::showsPay($form) && ! $this->pay->canViewPay($request->user()), 403);
        $definition = ManualFormDefinitions::all()[$form];
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            ...array_map(fn (array $field): array => $field['rules'], $definition['fields']),
        ]);
        $export = $this->forms->create($form, $employee, (string) $request->user()?->getAuthIdentifier(), $data['idempotency_key'],
            array_intersect_key($data, $definition['fields']), RequestScope::ownerId($request));

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($export), 202);
    }

    public function retry(Request $request, string $export, string $form): JsonResponse
    {
        $row = $this->exports->find($export);
        abort_unless($row->form_type === $form, 404);
        abort_if(ManualFormDefinitions::showsPay($form) && ! $this->pay->canViewPay($request->user()), 403);
        if (ManualFormDefinitions::all()[$form]['candidate'] ?? false) {
            $this->requireCandidateAccess($request);
        } else {
            abort_if($row->employee_id === null, 404);
        }
        $scope = RequestScope::ownerId($request);
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $row->employee_id, $scope), 404);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($this->exports->retry($export, $form)), 202);
    }
}
