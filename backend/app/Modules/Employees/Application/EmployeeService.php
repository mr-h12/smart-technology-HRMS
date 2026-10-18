<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\PayMethod;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeDocument;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeManagerAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeePayChange;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Employees and their dated type/pay history. Methods return arrays shaped for the API. */
final class EmployeeService
{
    private const HIDDEN = ['created_by', 'updated_by', 'deleted_at'];

    private const CURRENT_TYPE = '(select t.type from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.effective_from <= ? order by t.effective_from desc limit 1)';

    private const CURRENT_MANAGER = '(select m.manager_id from employee_manager_assignments m where m.employee_id = employees.id and m.deleted_at is null and m.effective_from <= ? order by m.effective_from desc limit 1)';

    private const PAY_FIELDS = ['effective_from', 'salary', 'raise', 'insured_basic', 'daily_wage', 'shift_start', 'shift_end'];

    /**
     * @param  array{type?: string, search?: string, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters, ?string $ownerId = null): array
    {
        $query = Employee::query()->select('employees.*')->selectRaw(self::CURRENT_TYPE.' as current_type', [self::today()])->orderBy('code');

        if (isset($filters['type'])) {
            $query->whereRaw(self::CURRENT_TYPE.' = ?', [self::today(), $filters['type']]);
        }

        if ($ownerId !== null) {
            $query->whereRaw(self::CURRENT_MANAGER.' = ?', [self::today(), $ownerId]);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('name_ar', 'ilike', $like)
                ->orWhere('name_en', 'ilike', $like)
                ->orWhere('national_id', 'like', $like)
                ->orWhereRaw('code::text = ?', [$filters['search']]));
        }

        $page = $query->paginate(min(max($filters['per_page'] ?? 20, 1), 100));

        return [
            'data' => array_values($page->getCollection()->map(fn (Employee $e) => $this->summary($e))->all()),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_next_page' => $page->hasMorePages(),
                'has_previous_page' => $page->currentPage() > 1,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  identity fields plus type, effective_from and pay fields
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->assertBank($data);
        $type = EmployeeType::from($data['type']);
        $pay = $this->payRow($data);
        $this->assertPay($type, $pay);

        $id = DB::transaction(function () use ($data, $type, $pay): string {
            // ponytail: table-wide lock serialises employee creation; fine at HR-entry rates, use a sequence if bulk import needs speed.
            DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
            $code = (int) Employee::withTrashed()->max('code') + 1;

            $employee = Employee::create([...Arr::except($data, ['type', ...self::PAY_FIELDS]), 'code' => $code]);
            $employee->typeChanges()->create(['type' => $type->value, 'effective_from' => $data['effective_from']]);
            $employee->payChanges()->create($pay);

            return (string) $employee->getKey();
        });

        return $this->show($id);
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $e = Employee::with(['typeChanges', 'payChanges', 'documents', 'managerAssignments.manager'])->findOrFail($id);
        $types = $e->typeChanges->sortBy('effective_from')->values()->map(fn ($t) => $this->row($t))->all();
        $pays = $e->payChanges->sortBy('effective_from')->values()->map(fn ($p) => $this->row($p))->all();
        $managers = $e->managerAssignments->sortBy('effective_from')->values()->map(fn (EmployeeManagerAssignment $m) => [
            'id' => $m->id,
            'manager_id' => $m->manager_id,
            'manager_name' => $m->manager?->name,
            'effective_from' => $m->effective_from,
        ])->all();
        $today = self::today();
        $manager = EffectiveDated::at($managers, $today);

        return [
            ...$this->summary($e),
            'current_type' => EffectiveDated::at($types, $today)['type'] ?? null,
            'current_pay' => EffectiveDated::at($pays, $today),
            'type_changes' => $types,
            'pay_changes' => $pays,
            'current_manager' => $manager === null ? null : ['id' => $manager['manager_id'], 'name' => $manager['manager_name']],
            'manager_assignments' => $managers,
            'documents' => $e->documents->sortBy('kind')->values()->map(fn ($d) => $this->row($d))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $employee->fill($data);
        $this->assertBank($employee->only(['pay_method', 'bank_account']));
        $employee->save();

        return $this->show($id);
    }

    public function delete(string $id): void
    {
        Employee::findOrFail($id)->delete();
    }

    /**
     * @param  array{type: string, effective_from: string}  $data
     * @return array<string, mixed>
     */
    public function addTypeChange(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $this->assertFreeDate($employee->typeChanges(), $data['effective_from']);
        $employee->typeChanges()->create($data);

        return $this->show($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addPayChange(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $this->assertFreeDate($employee->payChanges(), $data['effective_from']);

        $types = $employee->typeChanges->map(fn ($t) => $this->row($t))->all();
        $type = EffectiveDated::at($types, $data['effective_from']);
        if ($type === null) {
            throw ValidationException::withMessages(['effective_from' => __('errors.no_type_on_date')]);
        }
        $pay = $this->payRow($data);
        $this->assertPay(EmployeeType::from($type['type']), $pay);

        $employee->payChanges()->create($pay);

        return $this->show($id);
    }

    public function isManagedBy(string $employeeId, string $userId): bool
    {
        return Employee::query()->whereKey($employeeId)->whereRaw(self::CURRENT_MANAGER.' = ?', [self::today(), $userId])->exists();
    }

    /**
     * @param  array{manager_id: string, effective_from: string}  $data
     * @return array<string, mixed>
     */
    public function assignManager(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $this->assertFreeDate($employee->managerAssignments(), $data['effective_from']);
        $employee->managerAssignments()->create($data);

        return $this->show($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertDocument(string $id, DocumentKind $kind, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $employee->documents()->updateOrCreate(['kind' => $kind->value], $data);

        return $this->show($id);
    }

    private static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }

    /** @param  array<string, mixed>  $data */
    private function assertBank(array $data): void
    {
        if (($data['pay_method'] ?? PayMethod::Cash->value) === PayMethod::Bank->value && blank($data['bank_account'] ?? null)) {
            throw ValidationException::withMessages(['bank_account' => __('errors.bank_account_required')]);
        }
    }

    /** @param  array<string, mixed>  $pay */
    private function assertPay(EmployeeType $type, array $pay): void
    {
        $need = $type === EmployeeType::DayLaborer ? 'daily_wage' : 'salary';
        if (blank($pay[$need] ?? null)) {
            throw ValidationException::withMessages([$need => __('errors.pay_field_required', [
                'field' => __('validation.attributes.'.$need),
                'type' => __('errors.employee_type.'.$type->value),
            ])]);
        }
    }

    /** @param  \Illuminate\Database\Eloquent\Relations\HasMany<*, *>  $relation */
    private function assertFreeDate($relation, string $date): void
    {
        if ($relation->where('effective_from', $date)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payRow(array $data): array
    {
        $pay = Arr::only($data, self::PAY_FIELDS);
        $pay['raise'] = blank($pay['raise'] ?? null) ? '0' : $pay['raise'];

        return $pay;
    }

    /** @return array<string, mixed> */
    private function summary(Employee $e): array
    {
        return Arr::except($e->toArray(), [...self::HIDDEN, 'type_changes', 'pay_changes', 'documents', 'manager_assignments']);
    }

    /** @return array<string, mixed> */
    private function row(EmployeeTypeChange|EmployeePayChange|EmployeeDocument $m): array
    {
        return Arr::except($m->toArray(), [...self::HIDDEN, 'employee_id']);
    }
}
