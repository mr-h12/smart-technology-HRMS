<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Domain\FareRequestPending;
use App\Modules\Sites\Domain\FareRequestStatus;
use App\Modules\Sites\Infrastructure\Eloquent\FareChangeRequest;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A site manager's requests to change one employee's daily transport fare at one of their sites, and HR's decision (owner, 2026-11-20).
 * The fare in force holds until HR approves; approving writes an employee override through FareService::saveOverride (its period lock
 * and same-key update), from the date HR picks. Notifications carry ids and the site's name only, never an amount (§9).
 */
final class FareRequests
{
    public function __construct(
        private readonly FareService $fares,
        private readonly PermissionHolders $holders,
        private readonly Notifier $notifier,
    ) {}

    /**
     * FareService::siteTeam with each row's request waiting for HR at this site, if any.
     *
     * @return array<string, mixed>
     */
    public function siteView(string $managerId, string $siteId, string $date): array
    {
        $view = $this->fares->siteTeam($managerId, $siteId, $date);
        $pending = FareChangeRequest::where('site_id', $siteId)->where('status', FareRequestStatus::Pending)
            ->whereIn('employee_id', array_column($view['rows'], 'employee_id'))->get()->keyBy('employee_id');
        $view['rows'] = array_map(fn (array $r) => [...$r, 'pending' => isset($pending[$r['employee_id']]) ? $this->pendingOf($pending[$r['employee_id']], $managerId) : null], $view['rows']);

        return $view;
    }

    /**
     * The site manager's request for one employee of their team at one of their sites, on today's team. A retry with the same form id
     * answers the first request; another manager's form id, a site not theirs or an employee not on that site's list is a 404.
     *
     * @param  array{employee_id: string, site_id: string, amount: string, reason: string, client_request_id?: ?string}  $data
     * @return array<string, mixed> the request as the manager reads it
     */
    public function submit(string $managerId, array $data, string $today): array
    {
        $key = $data['client_request_id'] ?? null;
        if ($key !== null && ($first = FareChangeRequest::where('client_request_id', $key)->first()) !== null) {
            return $first->created_by === $managerId ? $this->outcome($first) : throw new ModelNotFoundException;
        }
        $row = collect($this->fares->siteTeam($managerId, $data['site_id'], $today)['rows'])->firstWhere('employee_id', $data['employee_id'])
            ?? throw new ModelNotFoundException;

        try {
            $request = DB::transaction(function () use ($managerId, $data, $row, $key): FareChangeRequest {
                $this->refuseWhilePending($data['employee_id'], $data['site_id'], $managerId);

                return FareChangeRequest::create([
                    'employee_id' => $data['employee_id'], 'site_id' => $data['site_id'], 'current_amount' => $row['amount'],
                    'requested_amount' => $data['amount'], 'reason' => $data['reason'], 'client_request_id' => $key,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race: the same form id (a double tap) or another pending request for this employee and site.
            if ($key !== null && ($first = FareChangeRequest::where('client_request_id', $key)->first()) !== null && $first->created_by === $managerId) {
                return $this->outcome($first);
            }
            $this->refuseWhilePending($data['employee_id'], $data['site_id'], $managerId);
            throw $e;
        }

        $site = Site::withTrashed()->find($data['site_id']);
        foreach ($this->holders->userIds('fare.manage.all') as $id) {
            $this->notifier->notifyOnce($id, 'fare_requested', $today, [
                'employee_id' => $data['employee_id'], 'request_id' => (string) $request->id,
                'site_name_ar' => (string) $site?->name_ar, 'site_name_en' => $site?->name_en,
            ], (string) $request->id);
        }

        return $this->outcome($request->refresh());
    }

    /**
     * HR's list, newest first: pending only by default.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(?string $status, int $perPage, int $page): array
    {
        $q = FareChangeRequest::query()->orderByDesc('id');
        if ($status !== 'all') {
            $q->where('status', $status ?? FareRequestStatus::Pending->value);
        }

        return $this->paged($q, $perPage, $page, true);
    }

    /** @return array<string, mixed> one request as HR reads it (who asked included) */
    public function show(string $id): array
    {
        return $this->outcome(FareChangeRequest::findOrFail($id), true);
    }

    /**
     * The requests $managerId made, newest first, each with HR's decision once made.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function mine(string $managerId, int $perPage, int $page): array
    {
        return $this->paged(FareChangeRequest::where('created_by', $managerId)->orderByDesc('id'), $perPage, $page, false);
    }

    /** @return array<string, mixed> one of $managerId's requests; 404 for anyone else's */
    public function mineOne(string $managerId, string $id): array
    {
        return $this->outcome(FareChangeRequest::where('created_by', $managerId)->findOrFail($id));
    }

    /**
     * Approves (the requested amount, or HR's revised one, from $from) or refuses (with HR's reason). A stale version or a request
     * already decided is a 409 concurrency_conflict, so two HR decisions never both land. The manager hears after the commit.
     *
     * @return array<string, mixed> the decided request as HR reads it
     */
    public function decide(string $id, int $version, bool $approve, ?string $amount, ?string $from, ?string $reason, string $hrId, string $today): array
    {
        $r = DB::transaction(function () use ($id, $version, $approve, $amount, $from, $reason, $hrId): FareChangeRequest {
            $r = FareChangeRequest::lockForUpdate()->findOrFail($id);
            if ($r->status !== FareRequestStatus::Pending || $r->version !== $version) {
                throw new ConcurrencyConflict;
            }
            if ($approve) {
                $approved = $amount ?? (string) $r->requested_amount;
                $this->fares->saveOverride((string) $r->employee_id, ['site_id' => (string) $r->site_id, 'effective_from' => (string) $from, 'amount' => $approved]);
                $r->fill(['status' => FareRequestStatus::Approved, 'approved_amount' => $approved, 'effective_from' => $from]);
            } else {
                $r->fill(['status' => FareRequestStatus::Refused, 'refusal_reason' => $reason]);
            }
            $r->fill(['decided_by' => $hrId, 'decided_at' => now(), 'version' => $version + 1])->save();

            return $r;
        });
        if ($r->created_by !== null) {
            $this->notifier->notifyOnce($r->created_by, $approve ? 'fare_approved' : 'fare_refused', $today, ['employee_id' => (string) $r->employee_id, 'request_id' => (string) $r->id], (string) $r->id);
        }

        return $this->outcome($r->refresh(), true);
    }

    private function refuseWhilePending(string $employeeId, string $siteId, string $managerId): void
    {
        $pending = FareChangeRequest::where(['employee_id' => $employeeId, 'site_id' => $siteId, 'status' => FareRequestStatus::Pending])->first();
        if ($pending !== null) {
            throw new FareRequestPending($this->pendingOf($pending, $managerId));
        }
    }

    /** @return array{id: string, requested_amount: string, created_at: ?string, mine: bool} */
    private function pendingOf(FareChangeRequest $r, string $managerId): array
    {
        return ['id' => (string) $r->id, 'requested_amount' => (string) $r->requested_amount, 'created_at' => $r->created_at?->toIso8601String(), 'mine' => $r->created_by === $managerId];
    }

    /**
     * @param  Builder<FareChangeRequest>  $q
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    private function paged(Builder $q, int $perPage, int $page, bool $hr): array
    {
        $p = $q->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        return [
            'data' => array_values($p->getCollection()->map(fn (FareChangeRequest $r) => $this->outcome($r, $hr))->all()),
            'pagination' => [
                'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'total_pages' => $p->lastPage(),
                'has_next_page' => $p->hasMorePages(), 'has_previous_page' => $p->currentPage() > 1,
            ],
        ];
    }

    /**
     * `outcome`: pending, approved (as requested), revised (approved at another amount) or refused.
     * ponytail: three lookups a row (employee, site, users); a page is at most 100 rows, eager loads if it ever shows.
     *
     * @return array<string, mixed>
     */
    private function outcome(FareChangeRequest $r, bool $hr = false): array
    {
        $approved = $r->status === FareRequestStatus::Approved;
        $e = Employee::withTrashed()->find($r->employee_id, ['id', 'code', 'name_ar', 'name_en']);
        $site = Site::withTrashed()->find($r->site_id);
        $users = User::whereKey(array_filter([$r->created_by, $r->decided_by]))->pluck('name', 'id');

        return [
            'id' => (string) $r->id,
            'status' => $r->status->value,
            'outcome' => $approved && bccomp(Decimal::of((string) $r->approved_amount, 'approved_amount'), Decimal::of((string) $r->requested_amount, 'requested_amount'), 6) !== 0 ? 'revised' : $r->status->value,
            'current_amount' => $r->current_amount === null ? null : (string) $r->current_amount,
            'requested_amount' => (string) $r->requested_amount,
            'approved_amount' => $approved ? (string) $r->approved_amount : null,
            'effective_from' => $approved ? (string) $r->effective_from : null,
            'reason' => $r->reason,
            'refusal_reason' => $r->status === FareRequestStatus::Refused ? $r->refusal_reason : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'version' => $r->version,
            'site' => ['id' => (string) $r->site_id, 'name_ar' => (string) $site?->name_ar, 'name_en' => $site?->name_en],
            'employee' => ['id' => (string) $r->employee_id, 'code' => (int) $e?->code, 'name_ar' => (string) $e?->name_ar, 'name_en' => $e?->name_en],
            ...($hr ? ['requested_by' => $r->created_by === null ? null : ['id' => $r->created_by, 'name' => (string) ($users[$r->created_by] ?? '')],
                'decided_by' => $r->decided_by === null ? null : ['id' => $r->decided_by, 'name' => (string) ($users[$r->decided_by] ?? '')]] : []),
        ];
    }
}
