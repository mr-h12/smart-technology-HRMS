<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Presentation\RequestScope;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class EmployeeController
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'type' => ['sometimes', Rule::enum(EmployeeType::class)],
            'search' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $r = $this->employees->list($f, RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->employees->countsByType(RequestScope::ownerId($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(EmployeeRules::store());

        return ApiEnvelope::single($request, $this->employees->create($data), 201);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        return ApiEnvelope::single($request, $this->employees->show($employee, RequestScope::ownerId($request)));
    }

    public function update(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate(EmployeeRules::identity(true, $employee));

        return ApiEnvelope::single($request, $this->employees->update($employee, $data));
    }

    public function destroy(string $employee): Response
    {
        $this->employees->delete($employee);

        return response()->noContent();
    }

    public function typeChange(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(EmployeeType::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->employees->addTypeChange($employee, $data), 201);
    }

    public function payChange(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([...EmployeeRules::pay(), 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->employees->addPayChange($employee, $data), 201);
    }

    public function assignManager(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->employees->assignManager($employee, $data), 201);
    }

    public function document(Request $request, string $employee, string $kind): JsonResponse
    {
        $data = $request->validate([
            'received_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string'],
        ]);

        return ApiEnvelope::single($request, $this->employees->upsertDocument($employee, DocumentKind::from($kind), $data));
    }
}
