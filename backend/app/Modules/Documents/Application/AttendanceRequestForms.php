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

final class AttendanceRequestForms
{
    public const ADVANCE = 'advance_request';

    public const OVERTIME = 'overtime_request';

    public static function isUnsignedOnly(string $form): bool
    {
        return in_array($form, [self::ADVANCE, self::OVERTIME], true);
    }

    public function __construct(
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
        private readonly AuthorizeAction $authorize,
    ) {}

    /** @param array<string, bool|string> $fields */
    public function create(string $id, string $actor, string $key, array $fields, ?string $scope, string $type = 'advance'): stdClass
    {
        return DB::transaction(function () use ($id, $actor, $key, $fields, $scope, $type): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $request = $this->attendanceRequest($id, $scope, lock: true);
            abort_unless($request->type === $type, 404);
            $form = $type.'_request';
            $sourceKey = $form.'_id';
            $existing = DB::table('hr_form_exports')->where('requested_by', $actor)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($existing->form_type !== $form || $snapshot[$sourceKey] !== $id
                    || array_intersect_key($snapshot, $fields) != $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            $employeeId = (string) $request->employee_id;
            $employee = $this->employees->show($employeeId, $type === 'advance' ? null : $scope);
            $date = (string) $request->date;
            $details = $type === 'advance' ? [
                'hire_date' => $employee['hire_date'],
                'amount' => (string) $request->amount,
                'salary' => EffectiveDated::at($employee['pay_changes'], $date)['salary'] ?? null,
            ] : [
                'checkout_time' => (string) $request->out_time,
                'minutes' => (int) $request->minutes,
            ];
            $previous = $this->rows($id, $form)->orderByDesc('serial_number')->value('id');

            return $this->exports->storeUnsigned($form, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'gender'])),
                ...$fields, ...$details,
                $sourceKey => $id, 'request_date' => $date,
            ], $previous === null ? null : (string) $previous);
        });
    }

    /** @return list<stdClass> */
    public function history(string $id, ?string $scope, ?Authenticatable $actor): array
    {
        $request = $this->attendanceRequest($id, $scope);
        abort_if($request->type === 'advance' && ! $this->canViewPay($actor), 403);

        $form = match ($request->type) {
            'advance' => self::ADVANCE,
            'overtime' => self::OVERTIME,
            default => null,
        };

        return $form === null ? [] : array_values($this->rows($id, $form)
            ->orderByDesc('serial_number')->get(['id', 'serial_number', 'stage', 'status'])->all());
    }

    private function attendanceRequest(string $id, ?string $scope, bool $lock = false): stdClass
    {
        $request = DB::table('attendance_requests')->where('id', $id)
            ->whereNull('deleted_at')->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw new ModelNotFoundException;
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $request->employee_id, $scope), 404);

        return $request;
    }

    public function canViewPay(?Authenticatable $actor): bool
    {
        return $actor !== null && $this->authorize->decide($actor, 'employee.view')->allows(Scope::All);
    }

    private function rows(string $id, string $form): Builder
    {
        return DB::table('hr_form_exports')->where(['form_type' => $form, 'stage' => 'unsigned'])
            ->whereNull('deleted_at')->where('snapshot->'.$form.'_id', $id);
    }
}
