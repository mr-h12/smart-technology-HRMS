<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class OvertimeRequestPdf implements PdfDocument
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function rules(): array
    {
        return [
            'job_title' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:300'],
            'paper_decision_date' => ['missing'],
        ];
    }

    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        $request = DB::table('attendance_requests')->where(['id' => $subjectId, 'type' => 'overtime'])->whereNull('deleted_at')
            ->lockForUpdate()->first() ?? throw new ModelNotFoundException;
        $employeeId = (string) $request->employee_id;
        abort_if($scope !== null && ! $this->employees->isManagedBy($employeeId, $scope), 404);
        $employee = $this->employees->show($employeeId, $scope);

        return [$employeeId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'gender'])),
            ...$fields,
            'checkout_time' => (string) $request->out_time,
            'minutes' => (int) $request->minutes,
            'overtime_request_id' => $subjectId, 'request_date' => (string) $request->date,
        ]];
    }

    public function subjectColumn(): string
    {
        return 'snapshot->overtime_request_id';
    }

    public function view(array $snapshot): array
    {
        return ['pdf.overtime-request', [
            'plainPaper' => true,
            'workDate' => ArabicFormDate::format($snapshot['request_date']),
            'checkout' => substr($snapshot['checkout_time'], 0, 5),
            'duration' => ArabicServiceDuration::hoursAndMinutes($snapshot['minutes']),
        ]];
    }

    /** Never signed. */
    public function approval(stdClass $export, Authenticatable $actor): ?string
    {
        throw new ConcurrencyConflict;
    }
}
