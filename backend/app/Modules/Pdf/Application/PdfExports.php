<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\User;
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

/** The store of `hr_form_exports` rows, shared by every PdfType, with every read and write gate on them. */
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
     * A replay of the caller's idempotency key returns its row (checked before the subject is read again); the same key with
     * another type, subject or fields is a conflict. The new row supersedes the subject's latest unsigned row.
     *
     * @param  array<string, mixed>  $fields  validated with document($type)->rules()
     */
    public function create(PdfType $type, string $subjectId, string $key, array $fields, Authenticatable $actor, ?string $scope): stdClass
    {
        if ($scope !== null && ! $type->ownScope()) {
            throw AuthorizationRefused::of('hr_forms.generate');
        }
        $actorId = (string) $actor->getAuthIdentifier();

        return DB::transaction(function () use ($type, $subjectId, $key, $fields, $actor, $actorId, $scope): stdClass {
            DB::table('users')->where('id', $actorId)->lockForUpdate()->firstOrFail();
            $unsigned = fn (): Builder => $this->subjectRows($type, $subjectId)->where('stage', 'unsigned');
            $existing = DB::table('hr_form_exports')->where('requested_by', $actorId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $stored = json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (! $unsigned()->where('id', $existing->id)->exists() || array_intersect_key($stored, $fields) != $fields) {
                    throw new ConcurrencyConflict;
                }

                return $existing;
            }

            [$employeeId, $snapshot] = self::document($type)->snapshot($subjectId, $fields, $scope, $this->canViewPay($actor));
            $previous = $unsigned()->orderByDesc('serial_number')->value('id');
            $row = $this->storeUnsigned($type, $employeeId, $actorId, $key, $snapshot, $previous === null ? null : (string) $previous);
            // The fields only: the snapshot carries the salary.
            AuditLog::record('CREATED', 'hr_form_exports', (string) $row->id, null, [
                'form_type' => $type->value, 'serial_number' => $row->serial_number, 'stage' => 'unsigned',
                'regenerated_from_id' => $row->regenerated_from_id, 'fields' => $fields,
            ]);

            return $row;
        });
    }

    /** Signs the unsigned row with the caller's stored signature; approving it again answers the signed row. */
    public function approve(string $id, Authenticatable $approver): stdClass
    {
        return DB::transaction(function () use ($id, $approver): stdClass {
            $approverId = (string) $approver->getAuthIdentifier();
            $source = $this->find($id, lock: true);
            $stampLine = $this->approval($source, $approver);
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

    /** Failed rows only, rendered again on the same row. No audit row: GENERATED follows the render. */
    public function retry(string $id, Authenticatable $actor): stdClass
    {
        return DB::transaction(function () use ($id, $actor): stdClass {
            $this->retryable($this->find($id, lock: true), $actor);
            DB::table('hr_form_exports')->where('id', $id)->update([
                'status' => 'pending', 'failure_code' => null, 'updated_at' => now(),
            ]);

            return $this->find($id);
        });
    }

    /**
     * @param  array<string, mixed>  $filters  type, subject_id (with type), employee_id, stage, status, awaiting_approval, q, page, per_page
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function list(array $filters, Authenticatable $actor, ?string $scope): LengthAwarePaginator
    {
        $query = DB::table('hr_form_exports')->whereNull('deleted_at')->whereIn('form_type', self::served());
        if (! $this->canViewPay($actor)) {
            $query->whereRaw("not (form_type = ? and coalesce(snapshot->>'shows_pay', 'true') <> 'false')", [PdfType::AdvanceRequest->value]);
        }
        if ($scope !== null) {
            $query->whereIn('form_type', self::served(fn (PdfType $type) => $type->ownScope()))
                ->whereIn('employee_id', EmployeeReach::ids($scope, now('Africa/Cairo')->toDateString()));
        }
        $type = PdfType::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('form_type', $type->value)
                ->when($filters['subject_id'] ?? null, fn (Builder $q, string $subject) => $q->where(self::document($type)->subjectColumn(), $subject));
        }
        foreach (['employee_id', 'stage', 'status'] as $column) {
            $query->when($filters[$column] ?? null, fn (Builder $q, string $value) => $q->where($column, $value));
        }
        if (! empty($filters['awaiting_approval'])) {
            $query->whereIn('form_type', self::served(fn (PdfType $type) => ($permission = $type->approvePermission()) !== null && $this->holdsAll($actor, $permission)))
                ->where('stage', 'unsigned')->where('status', 'ready')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('hr_form_exports as successor')
                    ->where(fn (Builder $q) => $q->whereColumn('successor.unsigned_export_id', 'hr_form_exports.id')
                        ->orWhereColumn('successor.regenerated_from_id', 'hr_form_exports.id')));
        }
        $search = (string) ($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->whereIn('employee_id', Employee::query()->matching($search)->select('id'))
                ->when(preg_match('/^(0|[1-9]\d{0,8})$/D', $search) === 1, fn (Builder $q) => $q->orWhere('serial_number', (int) $search)));
        }

        return $query->orderByDesc('serial_number')->orderByDesc('stage')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25), page: (int) ($filters['page'] ?? 1));
    }

    /** The row behind every read gate, else 404: missing, deleted, of a type not served, pay-bearing for a caller without pay, or out of an own scope. */
    public function visible(string $id, Authenticatable $actor, ?string $scope): stdClass
    {
        $row = $this->find($id);
        if (! $this->shows($row, $actor, $scope)) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /**
     * What the caller may do with the row now; approve and retry run the routes' own checks.
     *
     * @return list<string>
     */
    public function actions(stdClass $row, Authenticatable $actor): array
    {
        $actions = $row->status === 'ready' ? ['download'] : [];
        $permission = PdfType::from((string) $row->form_type)->approvePermission();
        // ponytail: up to three queries per signable row (successors, the leave request); a page holds at most 100 rows
        if ($row->stage === 'unsigned' && $row->status === 'ready' && $permission !== null && $this->holdsAll($actor, $permission)
            && self::passes(fn () => $this->approval($row, $actor))
            && ! DB::table('hr_form_exports')->where('unsigned_export_id', $row->id)->exists()) {
            $actions[] = 'approve';
        }
        if ($row->status === 'failed' && self::passes(fn () => $this->retryable($row, $actor))) {
            $actions[] = 'retry';
        }

        return $actions;
    }

    public function find(string $id, bool $lock = false): stdClass
    {
        return DB::table('hr_form_exports')->where('id', $id)->whereNull('deleted_at')->whereIn('form_type', self::served())
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() ?? throw new ModelNotFoundException;
    }

    /** Pay-bearing exports (the advance carries the salary) need employee.view at scope All. */
    public function canViewPay(Authenticatable $actor): bool
    {
        return $this->holdsAll($actor, 'employee.view');
    }

    /** Approve's refusals in order: 404 not visible, 409 never signed, 403 no permission, 409 state, then the type's signer rule. */
    private function approval(stdClass $row, Authenticatable $actor): ?string
    {
        $type = PdfType::from((string) $row->form_type);
        $permission = $type->approvePermission();
        if (! $this->shows($row, $actor, $permission === null ? null : $this->ownerOf($actor, $permission))) {
            throw new ModelNotFoundException;
        }
        if ($permission === null) {
            throw new ConcurrencyConflict;
        }
        if (! $this->authorize->decide($actor, $permission)->granted()) {
            throw AuthorizationRefused::of($permission);
        }
        if ($row->stage !== 'unsigned' || $row->status !== 'ready'
            || DB::table('hr_form_exports')->where('regenerated_from_id', $row->id)->exists()) {
            throw new ConcurrencyConflict;
        }

        return self::document($type)->approval($row, $actor);
    }

    /** Retry's refusals in order: 403 no permission (generate for an unsigned row, the type's approve for a signed one), 404 not visible, 409 state or another approver. */
    private function retryable(stdClass $row, Authenticatable $actor): void
    {
        $permission = $row->stage === 'signed'
            ? (PdfType::from((string) $row->form_type)->approvePermission() ?? throw new ConcurrencyConflict)
            : 'hr_forms.generate';
        if (! $this->authorize->decide($actor, $permission)->granted()) {
            throw AuthorizationRefused::of($permission);
        }
        if (! $this->shows($row, $actor, $this->ownerOf($actor, $permission))) {
            throw new ModelNotFoundException;
        }
        if ($row->status !== 'failed' || ($row->stage === 'signed' && $row->approved_by !== (string) $actor->getAuthIdentifier())) {
            throw new ConcurrencyConflict;
        }
    }

    /** The read gates on one row: pay, and for an own-scope caller the type's ownScope() and the employee in reach today. */
    private function shows(stdClass $row, Authenticatable $actor, ?string $scope): bool
    {
        $type = PdfType::from((string) $row->form_type);
        $bearsPay = $type === PdfType::AdvanceRequest
            && (json_decode((string) $row->snapshot, true, flags: JSON_THROW_ON_ERROR)['shows_pay'] ?? true) !== false;

        return (! $bearsPay || $this->canViewPay($actor))
            && ($scope === null || ($type->ownScope() && $row->employee_id !== null && $this->employees->isManagedBy((string) $row->employee_id, $scope)));
    }

    private function holdsAll(Authenticatable $actor, string $permission): bool
    {
        return $this->authorize->decide($actor, $permission)->allows(Scope::All);
    }

    /** RequestScope semantics for one permission: the caller's id when it is held below All only, else null (not held is refused separately). */
    private function ownerOf(Authenticatable $actor, string $permission): ?string
    {
        $decision = $this->authorize->decide($actor, $permission);

        return $decision->granted() && ! $decision->allows(Scope::All) ? (string) $actor->getAuthIdentifier() : null;
    }

    private static function passes(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (AuthorizationRefused|ConcurrencyConflict|ModelNotFoundException) {
            return false;
        }
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
     * The served types (all four by default). Rows of the nine postponed form types stay in the table but are never served (owner, 2026-11-13).
     *
     * @param  (callable(PdfType): bool)|null  $which
     * @return list<string>
     */
    private static function served(?callable $which = null): array
    {
        return array_column(array_filter(PdfType::cases(), $which), 'value');
    }
}
