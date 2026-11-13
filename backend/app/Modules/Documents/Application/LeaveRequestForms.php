<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class LeaveRequestForms
{
    public const FORM = 'leave_request';

    public function __construct(
        private readonly ExperienceCertificates $exports,
        private readonly EmployeeService $employees,
        private readonly LeaveBalances $balances,
    ) {}

    /** @param array<string, string> $fields */
    public function create(string $id, string $actor, ?string $scope, string $key, array $fields): stdClass
    {
        return DB::transaction(function () use ($id, $actor, $scope, $key, $fields): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $request = DB::table('leave_requests')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first()
                ?? throw new ModelNotFoundException;
            $employeeId = (string) $request->employee_id;
            $employee = $this->employees->show($employeeId, $scope);
            $existing = DB::table('hr_form_exports')->where('requested_by', $actor)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($existing->form_type !== self::FORM || $existing->stage !== 'unsigned'
                    || $snapshot['leave_request_id'] !== $id || array_intersect_key($snapshot, $fields) != $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            $previous = DB::table('hr_form_exports')->where('form_type', self::FORM)->where('stage', 'unsigned')
                ->where('snapshot->leave_request_id', $id)->orderByDesc('serial_number')->value('id');
            $year = (int) substr((string) $request->from_date, 0, 4);
            $balance = $this->balances->forEmployee($employeeId, $year)['balance'];

            return $this->exports->storeUnsigned(self::FORM, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'department', 'gender'])),
                ...$fields,
                'leave_request_id' => $id, 'kind' => $request->kind, 'from_date' => $request->from_date,
                'to_date' => $request->to_date, 'days' => (int) $request->days, 'reason' => $request->note,
                'balance' => $balance, 'balance_year' => $year,
            ], $previous === null ? null : (string) $previous);
        });
    }

    /** @return array<string, mixed> */
    public function history(string $id, ?string $scope, string $actor): array
    {
        $request = DB::table('leave_requests')->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw new ModelNotFoundException;
        $this->employees->show((string) $request->employee_id, $scope);
        $exports = DB::table('hr_form_exports')->where('form_type', self::FORM)->whereNull('deleted_at')
            ->where('snapshot->leave_request_id', $id)->orderByDesc('serial_number')->orderByDesc('stage')
            ->get(['id', 'serial_number', 'stage', 'status', 'approved_by', 'unsigned_export_id']);

        return ['exports' => $exports, 'is_decider' => $request->status === LeaveStatus::Approved->value && $request->decided_by === $actor];
    }

    public function approve(string $export, string $actor): stdClass
    {
        return DB::transaction(function () use ($export, $actor): stdClass {
            $source = $this->exports->find($export);
            abort_unless($source->form_type === self::FORM, 404);
            $snapshot = json_decode((string) $source->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $request = DB::table('leave_requests')->where('id', $snapshot['leave_request_id'])->whereNull('deleted_at')
                ->lockForUpdate()->first() ?? throw new ModelNotFoundException;
            if ($request->status !== LeaveStatus::Approved->value) {
                throw new ConcurrencyConflict;
            }
            abort_unless($request->decided_by === $actor, 403);

            return $this->exports->approve($export, $actor, self::FORM, $request->escalated_at === null ? 'hr' : 'company_manager');
        });
    }
}
