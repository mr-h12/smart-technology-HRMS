<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Localization\ArabicFormDate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class AdvanceRequestPdf implements PdfDocument
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function rules(): array
    {
        return [
            'job_title' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:300'],
            'repayment_method' => ['required', 'in:receipts,cash,salary_deduction'],
            'previous_advance' => ['required', 'boolean'],
            'finance_remarks' => ['nullable', 'string', 'max:300'],
            'paper_decision_date' => ['missing'],
        ];
    }

    public function normalise(array $fields): array
    {
        return [...$fields, 'previous_advance' => (bool) $fields['previous_advance'], 'finance_remarks' => $fields['finance_remarks'] ?? ''];
    }

    /** The snapshot carries the salary, so a caller without pay may not make one. */
    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        if (! $showsPay) {
            throw AuthorizationRefused::of('employee.view');
        }
        $request = DB::table('attendance_requests')->where(['id' => $subjectId, 'type' => 'advance'])->whereNull('deleted_at')
            ->lockForUpdate()->first() ?? throw new ModelNotFoundException;
        $employeeId = (string) $request->employee_id;
        if ($scope !== null && ! $this->employees->isManagedBy($employeeId, $scope)) {
            throw new ModelNotFoundException;
        }
        $employee = $this->employees->show($employeeId);
        $date = (string) $request->date;

        return [$employeeId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'gender'])),
            ...$fields,
            'shows_pay' => $showsPay,
            'hire_date' => $employee['hire_date'],
            'amount' => (string) $request->amount,
            'salary' => EffectiveDated::at($employee['pay_changes'], $date)['salary'] ?? null,
            'advance_request_id' => $subjectId, 'request_date' => $date,
        ]];
    }

    public function subjectColumn(): string
    {
        return 'snapshot->advance_request_id';
    }

    public function view(array $snapshot): array
    {
        return ['pdf.advance-request', [
            'plainPaper' => true,
            'requestDate' => ArabicFormDate::format($snapshot['request_date']),
            'hireDate' => $snapshot['hire_date'] === null ? null : ArabicFormDate::format($snapshot['hire_date']),
            'amount' => bcround($snapshot['amount'], 2),
            'salary' => $snapshot['salary'] === null ? null : bcround($snapshot['salary'], 2),
        ]];
    }

    /** Never signed. */
    public function approval(stdClass $export, Authenticatable $actor, bool $lock): ?string
    {
        throw new ConcurrencyConflict;
    }
}
