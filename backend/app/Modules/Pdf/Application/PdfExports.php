<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Modules\Pdf\Application\Types\AdvanceRequestPdf;
use App\Modules\Pdf\Application\Types\ExperienceCertificatePdf;
use App\Modules\Pdf\Application\Types\LeaveRequestPdf;
use App\Modules\Pdf\Application\Types\OvertimeRequestPdf;
use App\Modules\Pdf\Application\Types\PdfDocument;
use App\Modules\Pdf\Domain\PdfType;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** The store of `hr_form_exports` rows, shared by every PdfType. */
final class PdfExports
{
    public function __construct(private readonly EmployeeService $employees, private readonly AuthorizeAction $authorize) {}

    public static function document(PdfType $type): PdfDocument
    {
        return app(match ($type) {
            PdfType::ExperienceCertificate => ExperienceCertificatePdf::class,
            PdfType::LeaveRequest => LeaveRequestPdf::class,
            PdfType::AdvanceRequest => AdvanceRequestPdf::class,
            PdfType::OvertimeRequest => OvertimeRequestPdf::class,
        });
    }

    /**
     * A replay of the caller's idempotency key returns its row; the same key with another payload is a conflict.
     * The certificate regenerates only from the unsigned row the client names ($regeneratedFrom, or none);
     * every other type supersedes its subject's latest unsigned row.
     *
     * @param  array<string, mixed>  $fields
     */
    public function create(PdfType $type, string $subjectId, string $key, array $fields, Authenticatable $actor, ?string $scope, ?string $regeneratedFrom = null): stdClass
    {
        $document = self::document($type);
        $actorId = (string) $actor->getAuthIdentifier();
        $showsPay = $this->canViewPay($actor);
        $named = $type === PdfType::ExperienceCertificate;

        return DB::transaction(function () use ($type, $subjectId, $key, $fields, $actorId, $scope, $showsPay, $regeneratedFrom, $document, $named): stdClass {
            DB::table('users')->where('id', $actorId)->lockForUpdate()->firstOrFail();
            [$employeeId, $snapshot] = $document->snapshot($subjectId, $fields, $scope, $showsPay);
            $unsigned = fn (): Builder => $this->subjectRows($type, $subjectId)->where('stage', 'unsigned');

            $existing = DB::table('hr_form_exports')->where('requested_by', $actorId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $asked = array_intersect_key($snapshot, $fields);
                if (array_intersect_key(json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR), $asked) != $asked
                    || ($named && $existing->regenerated_from_id !== $regeneratedFrom)
                    || ! $unsigned()->where('id', $existing->id)->exists()) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            if ($named && $regeneratedFrom !== null) {
                $this->find($regeneratedFrom, lock: true);
                if (! $unsigned()->where('id', $regeneratedFrom)->exists()
                    || DB::table('hr_form_exports')->where('regenerated_from_id', $regeneratedFrom)->exists()) {
                    throw new ConcurrencyConflict;
                }
            }
            $previous = $named ? $regeneratedFrom : $unsigned()->orderByDesc('serial_number')->value('id');

            return $this->storeUnsigned($type, $employeeId, $actorId, $key, $snapshot, $previous === null ? null : (string) $previous);
        });
    }

    public function approve(PdfType $type, string $id, Authenticatable $approver): stdClass
    {
        return DB::transaction(function () use ($type, $id, $approver): stdClass {
            $approverId = (string) $approver->getAuthIdentifier();
            $source = $this->find($id, lock: true);
            if ($source->form_type !== $type->value) {
                throw new ConcurrencyConflict;
            }
            $stampLine = self::document($type)->approval($source, $approver);
            if ($source->stage !== 'unsigned' || $source->status !== 'ready'
                || DB::table('hr_form_exports')->where('regenerated_from_id', $id)->exists()) {
                throw new ConcurrencyConflict;
            }
            $existing = DB::table('hr_form_exports')->where('unsigned_export_id', $id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $signature = DB::table('hr_signature_images')->where('owner_id', $approverId)->whereNull('deleted_at')
                ->orderByDesc('version')->first(['id', 'version']);
            if ($signature === null) {
                throw ValidationException::withMessages(['signature' => __('errors.certificate_signature_required')]);
            }
            $snapshot = $source->snapshot;
            if ($stampLine !== null) {
                $snapshot = json_encode([
                    ...json_decode((string) $snapshot, true, flags: JSON_THROW_ON_ERROR),
                    'stamp_line' => $stampLine, 'decider_id' => $approverId, 'signature_version' => (int) $signature->version,
                ], JSON_THROW_ON_ERROR);
            }
            $viewer = Auth::user();
            $impersonator = $viewer instanceof User ? $viewer->viewAs?->byId : null;
            $signedId = (string) Str::uuid7();
            $now = now();
            DB::table('hr_form_exports')->insert([
                'id' => $signedId, 'form_type' => $source->form_type, 'serial_number' => $source->serial_number,
                'stage' => 'signed', 'status' => 'pending', 'employee_id' => $source->employee_id,
                'requested_by' => $approverId, 'idempotency_key' => (string) Str::uuid7(),
                'template_version' => $source->template_version, 'snapshot' => $snapshot,
                'storage_path' => "hr-forms/{$signedId}.pdf", 'unsigned_export_id' => $id,
                'approved_by' => $approverId, 'approved_at' => $now, 'signature_image_id' => $signature->id,
                'impersonator_id' => $impersonator, 'created_by' => $impersonator ?? $approverId,
                'updated_by' => $impersonator ?? $approverId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            AuditLog::record('APPROVED', 'hr_form_exports', $signedId, null, [
                'unsigned_export_id' => $id, 'serial_number' => $source->serial_number,
            ]);

            return $this->find($signedId);
        });
    }

    /** A type that is never signed retries its unsigned row, a signed type its signed row; $approver, when given, must have signed it. */
    public function retry(PdfType $type, string $id, ?string $approver = null): stdClass
    {
        return DB::transaction(function () use ($type, $id, $approver): stdClass {
            $export = $this->find($id, lock: true);
            $stage = $type->approvePermission() === null ? 'unsigned' : 'signed';
            if ($export->form_type !== $type->value || $export->stage !== $stage || $export->status !== 'failed'
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
    public function history(string $employee, ?PdfType $type = null, bool $includePay = false): LengthAwarePaginator
    {
        return DB::table('hr_form_exports')->where('employee_id', $employee)->whereNull('deleted_at')
            ->whereIn('form_type', self::served())
            ->when($type, fn ($query, PdfType $type) => $query->where('form_type', $type->value))
            ->when(! $includePay, fn ($query) => $query->where('form_type', '!=', PdfType::AdvanceRequest->value))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    public function awaitingApproval(): LengthAwarePaginator
    {
        return DB::table('hr_form_exports as source')->where('source.form_type', PdfType::ExperienceCertificate->value)
            ->where('source.stage', 'unsigned')->where('source.status', 'ready')
            ->whereNull('source.deleted_at')->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('hr_form_exports as successor')
            ->where(fn ($q) => $q->whereColumn('successor.unsigned_export_id', 'source.id')
                ->orWhereColumn('successor.regenerated_from_id', 'source.id')))
            ->orderBy('source.created_at')->orderBy('source.id')->paginate(25, ['source.*']);
    }

    /** @return array<string, mixed> */
    public function leaveHistory(string $id, ?string $scope, string $actor): array
    {
        $request = DB::table('leave_requests')->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw new ModelNotFoundException;
        $this->employees->show((string) $request->employee_id, $scope);
        $exports = $this->subjectRows(PdfType::LeaveRequest, $id)->whereNull('deleted_at')
            ->orderByDesc('serial_number')->orderByDesc('stage')
            ->get(['id', 'serial_number', 'stage', 'status', 'approved_by', 'unsigned_export_id']);

        return ['exports' => $exports, 'is_decider' => $request->status === LeaveStatus::Approved->value && $request->decided_by === $actor];
    }

    /** @return list<stdClass> */
    public function attendanceHistory(string $id, ?string $scope, ?Authenticatable $actor): array
    {
        $request = DB::table('attendance_requests')->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw new ModelNotFoundException;
        abort_if($scope !== null && ! $this->employees->isManagedBy((string) $request->employee_id, $scope), 404);
        abort_if($request->type === 'advance' && ! $this->canViewPay($actor), 403);

        $type = match ($request->type) {
            'advance' => PdfType::AdvanceRequest,
            'overtime' => PdfType::OvertimeRequest,
            default => null,
        };

        return $type === null ? [] : array_values($this->subjectRows($type, $id)->where('stage', 'unsigned')->whereNull('deleted_at')
            ->orderByDesc('serial_number')->get(['id', 'serial_number', 'stage', 'status'])->all());
    }

    public function find(string $id, bool $lock = false): stdClass
    {
        return DB::table('hr_form_exports')->where('id', $id)->whereNull('deleted_at')->whereIn('form_type', self::served())
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() ?? throw new ModelNotFoundException;
    }

    /** Pay-bearing exports (the advance carries the salary) need employee.view at scope All. */
    public function canViewPay(?Authenticatable $actor): bool
    {
        return $actor !== null && $this->authorize->decide($actor, 'employee.view')->allows(Scope::All);
    }

    /** @param array<string, mixed> $snapshot */
    private function storeUnsigned(PdfType $type, string $employee, string $actor, string $key, array $snapshot, ?string $previousId): stdClass
    {
        $id = (string) Str::uuid7();
        $now = now();
        DB::table('hr_form_serial_counters')->insertOrIgnore(['form_type' => $type->value, 'last_serial' => 0]);
        $counter = DB::table('hr_form_serial_counters')->where('form_type', $type->value);
        $serial = (int) $counter->lockForUpdate()->firstOrFail()->last_serial + 1;
        $counter->update(['last_serial' => $serial]);
        DB::table('hr_form_exports')->insert([
            'id' => $id, 'form_type' => $type->value, 'serial_number' => $serial,
            'stage' => 'unsigned', 'status' => 'pending', 'employee_id' => $employee,
            'requested_by' => $actor, 'idempotency_key' => $key, 'template_version' => 1,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'storage_path' => "hr-forms/{$id}.pdf", 'regenerated_from_id' => $previousId,
            'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($id);
    }

    private function subjectRows(PdfType $type, string $subjectId): Builder
    {
        return DB::table('hr_form_exports')->where('form_type', $type->value)->where(self::document($type)->subjectColumn(), $subjectId);
    }

    /**
     * Rows of the nine postponed form types stay in the table but are never served (owner, 2026-11-13).
     *
     * @return list<string>
     */
    private static function served(): array
    {
        return array_column(PdfType::cases(), 'value');
    }
}
