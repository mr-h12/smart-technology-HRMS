<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class LeaveRequestPdf implements PdfDocument
{
    public function __construct(private readonly EmployeeService $employees, private readonly LeaveBalances $balances) {}

    public function rules(): array
    {
        return [
            'job_title' => ['required', 'string', 'max:160'],
            'notification_method' => ['nullable', 'string', 'max:160'],
            'manager_opinion' => ['nullable', 'string', 'max:360'],
            'substitute' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        $request = DB::table('leave_requests')->where('id', $subjectId)->whereNull('deleted_at')->lockForUpdate()->first()
            ?? throw new ModelNotFoundException;
        $employeeId = (string) $request->employee_id;
        $employee = $this->employees->show($employeeId, $scope);
        $year = (int) substr((string) $request->from_date, 0, 4);

        return [$employeeId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'department', 'gender'])),
            ...array_map(fn (mixed $value): mixed => $value ?? '', ['notification_method' => null, 'manager_opinion' => null, 'substitute' => null, ...$fields]),
            'leave_request_id' => $subjectId, 'kind' => $request->kind, 'from_date' => $request->from_date,
            'to_date' => $request->to_date, 'days' => (int) $request->days, 'reason' => $request->note,
            'balance' => $this->balances->forEmployee($employeeId, $year)['balance'], 'balance_year' => $year,
        ]];
    }

    public function subjectColumn(): string
    {
        return 'snapshot->leave_request_id';
    }

    public function view(array $snapshot): array
    {
        $remaining = $snapshot['balance']['remaining'] ?? null;

        return ['pdf.leave-request', [
            'plainPaper' => true,
            'duration' => ArabicServiceDuration::days((int) $snapshot['days']),
            'from' => ArabicFormDate::format($snapshot['from_date']),
            'to' => ArabicFormDate::format($snapshot['to_date']),
            'balanceDays' => match ($remaining) {
                null => 'غير متاح', 0 => '0 يوم', default => ArabicServiceDuration::days((int) $remaining)
            },
        ]];
    }

    /** Only the person who approved the leave request signs its form; the stamp line names who decided it. */
    public function approval(stdClass $export, Authenticatable $actor): string
    {
        $snapshot = json_decode((string) $export->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $request = DB::table('leave_requests')->where('id', $snapshot['leave_request_id'])->whereNull('deleted_at')
            ->lockForUpdate()->first() ?? throw new ModelNotFoundException;
        if ($request->status !== LeaveStatus::Approved->value) {
            throw new ConcurrencyConflict;
        }
        if ($request->decided_by !== (string) $actor->getAuthIdentifier()) {
            throw AuthorizationRefused::of('hr_forms.approve_leave_request');
        }

        return $request->escalated_at === null ? 'hr' : 'company_manager';
    }
}
