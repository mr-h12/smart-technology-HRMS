<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\ManualFormDefinitions;
use App\Modules\Documents\Application\ManualHrForms;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ManualHrFormController
{
    public function __construct(
        private readonly ManualHrForms $forms,
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
    ) {}

    public function definitions(Request $request): JsonResponse
    {
        $forms = [];
        foreach (ManualFormDefinitions::all() as $type => $definition) {
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
        abort_unless($row->form_type === $form && $row->employee_id !== null, 404);
        $scope = RequestScope::ownerId($request);
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $row->employee_id, $scope), 404);

        return ApiEnvelope::single($request, ExperienceCertificateController::queue($this->exports->retry($export, $form)), 202);
    }
}
