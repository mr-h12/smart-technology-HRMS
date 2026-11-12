<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class AdvanceRequestForms
{
    public const FORM = 'advance_request';

    public function __construct(
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
        private readonly AuthorizeAction $authorize,
    ) {}

    /** @param array<string, bool|string> $fields */
    public function create(string $id, string $actor, string $key, array $fields, ?string $scope): stdClass
    {
        return DB::transaction(function () use ($id, $actor, $key, $fields, $scope): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $advance = $this->advance($id, $scope, lock: true);
            $existing = DB::table('hr_form_exports')->where('requested_by', $actor)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($existing->form_type !== self::FORM || $snapshot['advance_request_id'] !== $id
                    || array_intersect_key($snapshot, $fields) != $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            $employeeId = (string) $advance->employee_id;
            $employee = $this->employees->show($employeeId);
            $date = (string) $advance->date;
            $pay = EffectiveDated::at($employee['pay_changes'], $date);
            $previous = $this->rows($id)->orderByDesc('serial_number')->value('id');

            return $this->exports->storeUnsigned(self::FORM, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'hire_date'])),
                ...$fields,
                'advance_request_id' => $id,
                'request_date' => $date,
                'amount' => (string) $advance->amount,
                'salary' => $pay['salary'] ?? null,
            ], $previous === null ? null : (string) $previous);
        });
    }

    /** @return list<stdClass> */
    public function history(string $id, ?string $scope): array
    {
        $this->advance($id, $scope);

        return array_values($this->rows($id)->orderByDesc('serial_number')->get(['id', 'serial_number', 'stage', 'status'])->all());
    }

    private function advance(string $id, ?string $scope, bool $lock = false): stdClass
    {
        $advance = DB::table('attendance_requests')->where(['id' => $id, 'type' => 'advance'])
            ->whereNull('deleted_at')->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw new ModelNotFoundException;
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $advance->employee_id, $scope), 404);

        return $advance;
    }

    public function canViewPay(?Authenticatable $actor): bool
    {
        return $actor !== null && $this->authorize->decide($actor, 'employee.view')->allows(Scope::All);
    }

    private function rows(string $id): Builder
    {
        return DB::table('hr_form_exports')->where(['form_type' => self::FORM, 'stage' => 'unsigned'])
            ->whereNull('deleted_at')->where('snapshot->advance_request_id', $id);
    }
}
