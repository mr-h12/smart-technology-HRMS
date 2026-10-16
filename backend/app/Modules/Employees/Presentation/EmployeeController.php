<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\Gender;
use App\Modules\Employees\Domain\PayMethod;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class EmployeeController
{
    private const MONEY = ['nullable', 'regex:/^\d+(\.\d{1,6})?$/'];

    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'type' => ['sometimes', Rule::enum(EmployeeType::class)],
            'search' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $r = $this->employees->list($f);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->identity(false),
            'type' => ['required', Rule::enum(EmployeeType::class)],
            ...$this->pay(),
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->employees->create($data), 201);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        return ApiEnvelope::single($request, $this->employees->show($employee));
    }

    public function update(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate($this->identity(true, $employee));

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
        $data = $request->validate([...$this->pay(), 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->employees->addPayChange($employee, $data), 201);
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

    /** @return array<string, array<mixed>> */
    private function pay(): array
    {
        return [
            'salary' => self::MONEY,
            'raise' => self::MONEY,
            'insured_basic' => self::MONEY,
            'daily_wage' => self::MONEY,
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return array<string, array<mixed>> */
    private function identity(bool $patch, ?string $id = null): array
    {
        $r = fn (array $rules) => $patch ? ['sometimes', ...$rules] : $rules;
        $unique = fn (string $col) => Rule::unique('employees', $col)->ignore($id);

        return [
            'name_ar' => $r(['required', 'string']),
            'name_en' => ['nullable', 'string'],
            'fingerprint_name' => ['nullable', 'string', $unique('fingerprint_name')],
            'gender' => $r(['required', Rule::enum(Gender::class)]),
            'national_id' => ['nullable', 'digits:14', $unique('national_id')],
            'insurance_no' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'residence_id' => ['nullable', 'uuid'],
            'pay_method' => ['sometimes', Rule::enum(PayMethod::class)],
            'bank_account' => ['nullable', 'string'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'contract_end_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_reason' => ['nullable', 'string'],
            'hr_notes' => ['nullable', 'string'],
            'crm_user_id' => ['nullable', 'uuid'],
        ];
    }
}
