<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Leave\Application\LeaveDays;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Modules\Pdf\Domain\ValueSize;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class LeaveRequestPdf implements PdfDocument
{
    /** Printable limits by snapshot key (option A): every value at its limit still holds the one page at the smallest value size. */
    public const LIMITS = ['name_ar' => 90, 'department' => 90, 'job_title' => 60, 'notification_method' => 60, 'substitute' => 60,
        'manager_opinion' => 130, 'reason' => LeaveRequests::NOTE_MAX];

    /** The longest value that stays on one line at 16 pt; the job title and the notification method never take a second one. */
    public const ONE_LINE_AT_16 = ['name_ar' => 47, 'department' => 62, 'substitute' => 28, 'manager_opinion' => 38];

    /** The reason fits its first 25 characters after «لظروف» and up to 100 in one more line at 16 pt. */
    public const REASON_AT_16 = [25, 100];

    /** Below 16 pt: the fullest value's share of its limit, up to; the size every value of the copy prints at. */
    public const SIZES = [[0.45, 13], [0.6, 11], [0.65, 10], [1.0, 9]];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeRoster $roster,
        private readonly LeaveBalances $balances,
        private readonly LeaveDays $days,
    ) {}

    public function rules(): array
    {
        return [
            'job_title' => ['required', 'string', 'max:'.self::LIMITS['job_title']],
            'notification_method' => ['nullable', 'string', 'max:'.self::LIMITS['notification_method']],
            'manager_opinion' => ['nullable', 'string', 'max:'.self::LIMITS['manager_opinion']],
            'substitute' => ['nullable', 'string', 'max:'.self::LIMITS['substitute']],
        ];
    }

    public function normalise(array $fields): array
    {
        return array_map(fn (mixed $value): mixed => $value ?? '', ['notification_method' => null, 'manager_opinion' => null, 'substitute' => null, ...$fields]);
    }

    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        $request = DB::table('leave_requests')->where('id', $subjectId)->whereNull('deleted_at')->lockForUpdate()->first()
            ?? throw new ModelNotFoundException;
        $employeeId = (string) $request->employee_id;
        $employee = $this->employees->show($employeeId, $scope);
        // A value typed elsewhere (the employee record, the request's note) is refused here; HR shortens the record.
        foreach (['name_ar' => [self::LIMITS['name_ar'], $employee['name_ar']], 'department' => [self::LIMITS['department'], $employee['department']],
            'note' => [self::LIMITS['reason'], $request->note]] as $field => [$limit, $value]) {
            if (mb_strlen((string) $value) > $limit) {
                throw ValidationException::withMessages([$field => __('errors.leave_form_value_too_long', ['attribute' => __("validation.attributes.{$field}"), 'max' => $limit])]);
            }
        }
        $year = (int) substr((string) $request->from_date, 0, 4);
        // Without this request's own dates, which its approval writes as leave days: the balance before this leave.
        $dates = $this->days->dates($this->roster->find($employeeId) ?? throw new ModelNotFoundException, (string) $request->from_date, (string) $request->to_date);

        return [$employeeId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'department', 'gender'])),
            ...$fields,
            'leave_request_id' => $subjectId, 'kind' => $request->kind, 'from_date' => $request->from_date,
            'to_date' => $request->to_date, 'days' => (int) $request->days, 'reason' => $request->note,
            'balance' => $this->balances->forEmployee($employeeId, $year, $dates)['balance'], 'balance_year' => $year,
        ]];
    }

    public function subjectColumn(): string
    {
        return 'snapshot->leave_request_id';
    }

    public function view(array $snapshot): array
    {
        $remaining = $snapshot['balance']['remaining'] ?? null;
        $lengths = array_map(fn (string $key): int => mb_strlen((string) ($snapshot[$key] ?? '')), array_combine(array_keys(self::LIMITS), array_keys(self::LIMITS)));
        // 16 pt is chosen by lines, not by share: on the render a value within its one-line length costs no room, however near its limit.
        $spills = (int) ($lengths['reason'] > self::REASON_AT_16[0])
            + array_sum(array_map(fn (string $key, int $line): int => intdiv(max($lengths[$key] - 1, 0), $line), array_keys(self::ONE_LINE_AT_16), self::ONE_LINE_AT_16));
        $days = (int) $snapshot['days'];

        return ['pdf.leave-request', [
            'title' => 'نموذج طلب إجازة',
            'valueSize' => $spills <= 1 && $lengths['reason'] <= self::REASON_AT_16[1] ? 16 : ValueSize::byShare($lengths, self::LIMITS, self::SIZES)[1],
            // The genitive after «لمدة»; days() prints the nominative «يومان».
            'duration' => $days === 2 ? 'يومين' : ArabicServiceDuration::days($days),
            'from' => ArabicFormDate::spaced($snapshot['from_date']),
            'to' => ArabicFormDate::spaced($snapshot['to_date']),
            'balanceDays' => match ($remaining) {
                null => null, 0 => '0 يوم', default => ArabicServiceDuration::days((int) $remaining)
            },
        ]];
    }

    /** Only the person who approved the leave request signs its form; the stamp line names who decided it. */
    public function approval(stdClass $export, Authenticatable $actor, bool $lock): string
    {
        $snapshot = json_decode((string) $export->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $request = DB::table('leave_requests')->where('id', $snapshot['leave_request_id'])->whereNull('deleted_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() ?? throw new ModelNotFoundException;
        if (! $this->signable(DB::table('hr_form_exports')->where('id', $export->id), $actor)->exists()) {
            // signable() decides; the status only picks the refusal: not approved (409) or approved by someone else (403).
            throw $request->status === LeaveStatus::Approved->value ? AuthorizationRefused::of('hr_forms.approve_leave_request') : new ConcurrencyConflict;
        }

        return $request->escalated_at === null ? 'hr' : 'company_manager';
    }

    /** The leave request is approved and $actor decided it. */
    public function signable(Builder $exports, Authenticatable $actor): Builder
    {
        return $exports->whereExists(fn (Builder $requests) => $requests->selectRaw('1')->from('leave_requests')
            ->whereRaw("leave_requests.id = (hr_form_exports.snapshot->>'leave_request_id')::uuid")
            ->whereNull('leave_requests.deleted_at')->where('leave_requests.status', LeaveStatus::Approved->value)
            ->where('leave_requests.decided_by', (string) $actor->getAuthIdentifier()));
    }
}
