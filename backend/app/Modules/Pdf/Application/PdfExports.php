<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
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
     * A replay of the caller's idempotency key returns its row behind the read gates (checked before the subject is read again);
     * the same key with another type, subject or normalised fields, or on a deleted row, is a conflict. The new row supersedes the
     * subject's latest unsigned row of the same pay kind, so no answer names a row the caller cannot read.
     *
     * @param  array<string, mixed>  $fields  validated with document($type)->rules()
     * @return array{0: stdClass, 1: bool} [the row, whether this call made it]: only a new row is queued, a replay's row already was
     */
    public function create(PdfType $type, string $subjectId, string $key, array $fields, Authenticatable $actor, ?string $scope): array
    {
        if ($scope !== null && ! $type->ownScope()) {
            throw AuthorizationRefused::of('hr_forms.generate');
        }
        $actorId = (string) $actor->getAuthIdentifier();
        $showsPay = $this->canViewPay($actor);
        if ($type === PdfType::AdvanceRequest && ! $showsPay) {
            // The finance section is not answered by a caller without pay: what they send is ignored (owner, 2026-11-14).
            unset($fields['previous_advance'], $fields['finance_remarks']);
        }
        $fields = self::document($type)->normalise($fields);

        return DB::transaction(function () use ($type, $subjectId, $key, $fields, $actor, $actorId, $scope, $showsPay): array {
            DB::table('users')->where('id', $actorId)->lockForUpdate()->firstOrFail();
            $unsigned = fn (): Builder => $this->subjectRows($type, $subjectId)->where('stage', 'unsigned');
            // Deleted rows too: a soft-deleted row still holds its key in the unique (requested_by, idempotency_key) index.
            $existing = DB::table('hr_form_exports')->where('requested_by', $actorId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->deleted_at !== null || ! $unsigned()->where('id', $existing->id)->exists()) {
                    throw new ConcurrencyConflict;
                }
                // Before the fields: what a caller without pay sends is normalised differently, and a row they cannot read answers nothing.
                if (! $this->shows($existing, $actor, $scope)) {
                    throw new ModelNotFoundException;
                }
                if (! self::sameFields(json_decode((string) $existing->snapshot, true, flags: JSON_THROW_ON_ERROR), $fields)) {
                    throw new ConcurrencyConflict;
                }

                return [$existing, false];
            }

            [$employeeId, $snapshot] = self::document($type)->snapshot($subjectId, $fields, $scope, $showsPay);
            $previous = $unsigned()->whereRaw("coalesce(snapshot->>'shows_pay', 'true') = ?", [($snapshot['shows_pay'] ?? true) === false ? 'false' : 'true'])
                ->orderByDesc('serial_number')->value('id');
            $row = $this->storeUnsigned($type, $employeeId, $actorId, $key, $snapshot, $previous === null ? null : (string) $previous);
            // The fields only: the snapshot carries the salary.
            AuditLog::record('CREATED', 'hr_form_exports', (string) $row->id, null, [
                'form_type' => $type->value, 'serial_number' => $row->serial_number, 'stage' => 'unsigned',
                'regenerated_from_id' => $row->regenerated_from_id, 'fields' => $fields,
            ]);

            return [$row, true];
        });
    }

    /** Signs the unsigned row with the caller's stored signature; approving it again answers the signed row. */
    public function approve(string $id, Authenticatable $approver): stdClass
    {
        return DB::transaction(function () use ($id, $approver): stdClass {
            $approverId = (string) $approver->getAuthIdentifier();
            $source = $this->find($id, lock: true);
            $stampLine = $this->approval($source, $approver, lock: true);
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
            $impersonator = self::impersonator();
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

    /** Failed rows only, rendered again on the same row. A RETRIED audit row records who asked; GENERATED follows the render. */
    public function retry(string $id, Authenticatable $actor): stdClass
    {
        return DB::transaction(function () use ($id, $actor): stdClass {
            $this->retryable($this->find($id, lock: true), $actor);
            DB::table('hr_form_exports')->where('id', $id)->update([
                'status' => 'pending', 'failure_code' => null,
                'updated_by' => self::impersonator() ?? (string) $actor->getAuthIdentifier(), 'updated_at' => now(),
            ]);
            AuditLog::record('RETRIED', 'hr_form_exports', $id, ['status' => 'failed'], ['status' => 'pending']);

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
        $type = PdfType::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('form_type', $type->value)
                ->when($filters['subject_id'] ?? null, fn (Builder $q, string $subject) => $q->where(self::document($type)->subjectColumn(), $subject));
        }
        if ($scope !== null) {
            // An own-scope list names its subject (PdfController requires it). Its rows share one employee and request date, so the
            // read gate of one row decides them all.
            $query->whereIn('form_type', self::served(fn (PdfType $type) => $type->ownScope()));
            $sample = empty($filters['subject_id']) ? null : (clone $query)->first();
            if ($sample === null || ! $this->shows($sample, $actor, $scope)) {
                $query->whereRaw('false');
            }
        }
        foreach (['employee_id', 'stage', 'status'] as $column) {
            $query->when($filters[$column] ?? null, fn (Builder $q, string $value) => $q->where($column, $value));
        }
        if (! empty($filters['awaiting_approval'])) {
            // The rows whose actions() include approve: approval()'s gates, with each type's signer rule as SQL.
            $query->where(function (Builder $q) use ($actor): void {
                $q->whereRaw('false');
                foreach (self::served(fn (PdfType $type) => ($permission = $type->approvePermission()) !== null && $this->holdsAll($actor, $permission)) as $type) {
                    $q->orWhere(fn (Builder $q) => self::document(PdfType::from($type))
                        ->signable($q->where('form_type', $type)->where('template_version', PdfType::from($type)->templateVersion()), $actor));
                }
            })
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

        // Issue time first: serials count per type, so across types only the time says which is newer.
        return $query->orderByDesc('created_at')->orderByDesc('serial_number')->orderBy('stage')->orderByDesc('id')
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
            && self::passes(fn () => $this->approval($row, $actor, lock: false))
            && ! DB::table('hr_form_exports')->where('unsigned_export_id', $row->id)->exists()) {
            $actions[] = 'approve';
        }
        if ($row->status === 'failed' && self::passes(fn () => $this->retryable($row, $actor))) {
            $actions[] = 'retry';
        }

        return $actions;
    }

    /** A row whose render job never reached the queue fails as a failed render does, so Retry appears; a row the worker already settled is kept. */
    public function dispatchFailed(string $id): stdClass
    {
        DB::table('hr_form_exports')->where('id', $id)->where('status', 'pending')->update([
            'status' => 'failed', 'failure_code' => 'render_failed', 'updated_at' => now(),
        ]);

        return $this->find($id);
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

    /** Approve's refusals in order: 404 not visible, 409 never signed, 403 no permission, 409 state or old layout, then the type's signer rule. */
    private function approval(stdClass $row, Authenticatable $actor, bool $lock): ?string
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
        if ($row->stage !== 'unsigned' || $row->status !== 'ready' || ! self::currentLayout($row)
            || DB::table('hr_form_exports')->where('regenerated_from_id', $row->id)->exists()) {
            throw new ConcurrencyConflict;
        }

        return self::document($type)->approval($row, $actor, $lock);
    }

    /** Retry's refusals in order: 403 no permission (generate for an unsigned row, the type's approve for a signed one), 404 not visible, 409 state, another approver or old layout. */
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
        if ($row->status !== 'failed' || ($row->stage === 'signed' && $row->approved_by !== (string) $actor->getAuthIdentifier())
            || ! self::currentLayout($row)) {
            throw new ConcurrencyConflict;
        }
    }

    /** A row renders, is signed and is retried only in the layout version it was made for (the awaiting filter says it in SQL). */
    private static function currentLayout(stdClass $row): bool
    {
        return (int) $row->template_version === PdfType::from((string) $row->form_type)->templateVersion();
    }

    /** True or false for an advance (the copy with or without the salary; a snapshot older than the flag has the salary), null for the types that never carry pay. */
    public static function showsPay(stdClass $row): ?bool
    {
        return $row->form_type === PdfType::AdvanceRequest->value
            ? (json_decode((string) $row->snapshot, true, flags: JSON_THROW_ON_ERROR)['shows_pay'] ?? true) !== false
            : null;
    }

    /** The read gates on one row: pay, and for an own-scope caller the type's ownScope() and the employee in reach on the request's date. */
    private function shows(stdClass $row, Authenticatable $actor, ?string $scope): bool
    {
        if (self::showsPay($row) === true && ! $this->canViewPay($actor)) {
            return false;
        }
        if ($scope === null) {
            return true;
        }
        $requestDate = json_decode((string) $row->snapshot, true, flags: JSON_THROW_ON_ERROR)['request_date'] ?? null;

        return PdfType::from((string) $row->form_type)->ownScope() && $row->employee_id !== null
            && $this->employees->isManagedBy((string) $row->employee_id, $scope, $requestDate);
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

    /** The developer behind a view-as session, else null. */
    private static function impersonator(): ?string
    {
        $viewer = Auth::user();

        return $viewer instanceof User ? $viewer->viewAs?->byId : null;
    }

    /**
     * Type-strict and in any key order: the snapshot holds every normalised field with the identical value.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $fields
     */
    private static function sameFields(array $snapshot, array $fields): bool
    {
        $stored = array_intersect_key($snapshot, $fields);
        ksort($stored);
        ksort($fields);

        return $stored === $fields;
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
            'requested_by' => $actor, 'idempotency_key' => $key, 'template_version' => $type->templateVersion(),
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
