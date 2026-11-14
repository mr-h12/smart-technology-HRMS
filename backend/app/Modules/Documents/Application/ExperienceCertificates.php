<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ExperienceCertificates
{
    private const FORM = 'experience_certificate';

    // Rows of the nine postponed form types stay in the table but are never served (owner, 2026-11-13).
    private const SERVED = [self::FORM, LeaveRequestForms::FORM, AttendanceRequestForms::ADVANCE, AttendanceRequestForms::OVERTIME];

    public function __construct(private readonly EmployeeService $employees) {}

    public static function standardPraise(string $gender): string
    {
        return $gender === 'female'
            ? 'وكانت من أكفأ الموظفات اللاتي عملن بالشركة، وتميزت في أداء مهامها وتمتعت بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.'
            : 'وكان من أكفأ الموظفين الذين عملوا بالشركة، وتميز في أداء مهامه وتمتع بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.';
    }

    public function create(string $employeeId, string $actor, string $key, string $jobTitle, ?string $previousId, ?string $praise = null): stdClass
    {
        return DB::transaction(function () use ($employeeId, $actor, $key, $jobTitle, $previousId, $praise): stdClass {
            DB::table('users')->where('id', $actor)->lockForUpdate()->firstOrFail();
            $existing = DB::table('hr_form_exports')->where('requested_by', $actor)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $snapshot = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($existing->form_type !== self::FORM || $existing->stage !== 'unsigned'
                    || $existing->employee_id !== $employeeId || $existing->regenerated_from_id !== $previousId
                    || $snapshot['job_title'] !== $jobTitle
                    || ($snapshot['praise'] ?? self::standardPraise($snapshot['gender'])) !== ($praise ?? self::standardPraise($snapshot['gender']))) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            if ($previousId !== null) {
                $previous = $this->find($previousId, lock: true);
                if ($previous->employee_id !== $employeeId || $previous->stage !== 'unsigned' || $previous->form_type !== self::FORM
                    || DB::table('hr_form_exports')->where('regenerated_from_id', $previousId)->exists()) {
                    throw new ConcurrencyConflict;
                }
            }

            $employee = $this->employees->show($employeeId);
            foreach (['national_id', 'department', 'gender', 'hire_date'] as $field) {
                if (empty($employee[$field])) {
                    throw ValidationException::withMessages([$field => __('errors.certificate_employee_data')]);
                }
            }

            return $this->storeUnsigned(self::FORM, $employeeId, $actor, $key, [
                ...array_intersect_key($employee, array_flip(['name_ar', 'national_id', 'department', 'gender', 'hire_date', 'termination_date'])),
                'job_title' => $jobTitle, 'praise' => $praise ?? self::standardPraise($employee['gender']), 'issued_on' => now('Africa/Cairo')->toDateString(),
            ], $previousId);
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function storeUnsigned(string $form, ?string $employee, string $actor, string $key, array $snapshot, ?string $previousId): stdClass
    {
        $id = (string) Str::uuid7();
        $now = now();
        DB::table('hr_form_serial_counters')->insertOrIgnore(['form_type' => $form, 'last_serial' => 0]);
        $counter = DB::table('hr_form_serial_counters')->where('form_type', $form);
        $serial = (int) $counter->lockForUpdate()->firstOrFail()->last_serial + 1;
        $counter->update(['last_serial' => $serial]);
        DB::table('hr_form_exports')->insert([
            'id' => $id, 'form_type' => $form, 'serial_number' => $serial,
            'stage' => 'unsigned', 'status' => 'pending', 'employee_id' => $employee,
            'requested_by' => $actor, 'idempotency_key' => $key, 'template_version' => 1,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'storage_path' => "hr-forms/{$id}.pdf", 'regenerated_from_id' => $previousId,
            'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($id);
    }

    public function approve(string $id, string $approver, string $form = self::FORM, ?string $stampLine = null): stdClass
    {
        return DB::transaction(function () use ($id, $approver, $form, $stampLine): stdClass {
            $source = $this->find($id, lock: true);
            if ($source->form_type !== $form || $source->stage !== 'unsigned' || $source->status !== 'ready'
                || DB::table('hr_form_exports')->where('regenerated_from_id', $id)->exists()) {
                throw new ConcurrencyConflict;
            }
            $existing = DB::table('hr_form_exports')->where('unsigned_export_id', $id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $signature = DB::table('hr_signature_images')->where('owner_id', $approver)->whereNull('deleted_at')
                ->orderByDesc('version')->first(['id', 'version']);
            if ($signature === null) {
                throw ValidationException::withMessages(['signature' => __('errors.certificate_signature_required')]);
            }
            $snapshot = $source->snapshot;
            if ($stampLine !== null) {
                $snapshot = json_encode([
                    ...json_decode((string) $snapshot, true, flags: JSON_THROW_ON_ERROR),
                    'stamp_line' => $stampLine, 'decider_id' => $approver, 'signature_version' => (int) $signature->version,
                ], JSON_THROW_ON_ERROR);
            }
            $viewer = Auth::user();
            $impersonator = $viewer instanceof User ? $viewer->viewAs?->byId : null;
            $signedId = (string) Str::uuid7();
            $now = now();
            DB::table('hr_form_exports')->insert([
                'id' => $signedId, 'form_type' => $source->form_type, 'serial_number' => $source->serial_number,
                'stage' => 'signed', 'status' => 'pending', 'employee_id' => $source->employee_id,
                'requested_by' => $approver, 'idempotency_key' => (string) Str::uuid7(),
                'template_version' => $source->template_version, 'snapshot' => $snapshot,
                'storage_path' => "hr-forms/{$signedId}.pdf", 'unsigned_export_id' => $id,
                'approved_by' => $approver, 'approved_at' => $now, 'signature_image_id' => $signature->id,
                'impersonator_id' => $impersonator, 'created_by' => $impersonator ?? $approver,
                'updated_by' => $impersonator ?? $approver, 'created_at' => $now, 'updated_at' => $now,
            ]);
            AuditLog::record('APPROVED', 'hr_form_exports', $signedId, null, [
                'unsigned_export_id' => $id, 'serial_number' => $source->serial_number,
            ]);

            return $this->find($signedId);
        });
    }

    public function retry(string $id, string $form = self::FORM, ?string $approver = null): stdClass
    {
        return DB::transaction(function () use ($id, $form, $approver): stdClass {
            $export = $this->find($id, lock: true);
            $stage = AttendanceRequestForms::isUnsignedOnly($form) ? 'unsigned' : 'signed';
            if ($export->form_type !== $form || $export->stage !== $stage || $export->status !== 'failed'
                || ($approver !== null && $export->approved_by !== $approver)) {
                throw new ConcurrencyConflict;
            }
            DB::table('hr_form_exports')->where('id', $id)->update([
                'status' => 'pending', 'failure_code' => null, 'updated_at' => now(),
            ]);

            return $this->find($id);
        });
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    public function history(string $employee, ?string $form = null, bool $includePay = false): LengthAwarePaginator
    {
        return DB::table('hr_form_exports')->where('employee_id', $employee)->whereNull('deleted_at')
            ->whereIn('form_type', self::SERVED)
            ->when($form !== null, fn ($query) => $query->where('form_type', $form))
            ->when(! $includePay, fn ($query) => $query->where('form_type', '!=', AttendanceRequestForms::ADVANCE))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    public function awaitingApproval(): LengthAwarePaginator
    {
        return DB::table('hr_form_exports as source')->where('source.form_type', self::FORM)->where('source.stage', 'unsigned')->where('source.status', 'ready')
            ->whereNull('source.deleted_at')->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('hr_form_exports as successor')
            ->where(fn ($q) => $q->whereColumn('successor.unsigned_export_id', 'source.id')
                ->orWhereColumn('successor.regenerated_from_id', 'source.id')))
            ->orderBy('source.created_at')->orderBy('source.id')->paginate(25, ['source.*']);
    }

    public function find(string $id, bool $lock = false): stdClass
    {
        return DB::table('hr_form_exports')->where('id', $id)->whereNull('deleted_at')->whereIn('form_type', self::SERVED)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() ?? throw new ModelNotFoundException;
    }
}
