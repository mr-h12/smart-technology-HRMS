<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\ClientRequestReused;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\WageProposalKind;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeWageProposal;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Application\SiteService;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\Paginates;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The day-labor crew's writes (owner, 2026-11-04; PR #22 review). A day's crew is the day laborers whose site on that date is the
 * site: that day's crew change, else their home site. Adding, moving or removing someone writes a crew change for that date only, so
 * yesterday's records and tomorrow's roster stay as they were. A new day laborer a site manager adds has no home site and no pay row:
 * they join that day's crew, and their proposed wage waits for HR in employee_wage_proposals. Callers check who may do this, and
 * hold locked() around a check and the write it guards.
 */
final class DayCrew
{
    use Paginates;

    public function __construct(
        private readonly SiteService $sites,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * The site a proposal's laborer was added to: their first crew change, kept after a refusal took them off every crew (soft-deleted);
     * and their home sites, for a wage change's site on the day it was proposed.
     *
     * @return array<int|string, \Closure|string>
     */
    private static function firstCrew(): array
    {
        return ['employee.crewChanges' => fn ($q) => $q->withTrashed(), 'employee.siteAssignments'];
    }

    /**
     * The one day laborer employed on $date whose code is $search, or whose full name is (spaces collapsed, English case ignored):
     * a site manager adds someone they know, and never browses the company's laborers (owner, 2026-11-05). Two laborers with that
     * full name are `ambiguous`, and the code tells them apart. Someone on or past their last day is never offered, nor the searching
     * site manager's own record (owner, 2026-11-12).
     *
     * @return array{match: ?array{id: string, code: int, name_ar: string, name_en: ?string, site_id: ?string}, ambiguous: bool}
     */
    public function find(string $search, string $date, string $managerId): array
    {
        $search = trim((string) preg_replace('/\s+/u', ' ', $search));
        $rows = EmployeeReach::exceptOwn(Employee::with(['typeChanges', 'siteAssignments', 'crewChanges']), $managerId)
            ->whereRaw(Employee::TYPE_ON.' = ?', [$date, EmployeeType::DayLaborer->value])
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>', $date))
            ->where(fn (Builder $q) => preg_match('/^(0|[1-9]\d{0,8})$/D', $search) === 1
                ? $q->where('code', (int) $search)
                : $q->whereRaw("regexp_replace(btrim(name_ar), '\\s+', ' ', 'g') = ?", [$search])
                    ->orWhereRaw("lower(regexp_replace(btrim(coalesce(name_en, '')), '\\s+', ' ', 'g')) = lower(?)", [$search]))
            ->orderBy('code')->limit(2)->get();
        $e = $rows->count() === 1 ? $rows->first() : null;

        return [
            'match' => $e === null ? null : ['id' => (string) $e->id, 'code' => (int) $e->code, 'name_ar' => (string) $e->name_ar, 'name_en' => $e->name_en, 'site_id' => $this->siteOn($e, $date)],
            'ambiguous' => $rows->count() > 1,
        ];
    }

    /**
     * Runs $fn in one transaction with the employees' rows locked, so a check and the write it guards see the same state: a crew
     * change and a site-day save of the same person never interleave (PR #22 review B4). Locked by id, so two callers never deadlock.
     *
     * @template T
     *
     * @param  list<string>  $employeeIds
     * @param  \Closure(): T  $fn
     * @return T
     */
    public function locked(array $employeeIds, \Closure $fn): mixed
    {
        return DB::transaction(function () use ($employeeIds, $fn): mixed {
            $ids = array_values(array_unique($employeeIds));
            sort($ids);
            if ($ids !== []) {
                Employee::whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            return $fn();
        });
    }

    /** The day laborer's site on $date (crew change, else home site), or null; null too for anyone who is not a day laborer employed on $date. */
    public function laborerSiteOn(string $employeeId, string $date): ?string
    {
        $e = $this->laborer($employeeId, $date);

        return $e === null ? null : $this->siteOn($e, $date);
    }

    /** Whether a site manager may add them to a crew on $date: a day laborer employed then, and not on their last day (a refused proposal ends them that day). */
    public function isAddableOn(string $employeeId, string $date): bool
    {
        $e = $this->laborer($employeeId, $date);

        return $e !== null && ($e->termination_date === null || $e->termination_date > $date);
    }

    /**
     * Puts the employee on $siteId's crew for $date only, or on no crew when null. A change back to their home site removes the day's
     * change, so the day reads as their normal assignment again. Audited.
     */
    public function setCrew(string $employeeId, ?string $siteId, string $date): void
    {
        try {
            $this->writeCrew($employeeId, $siteId, $date);
        } catch (UniqueConstraintViolationException) {
            // Two requests changed the same person's day at once and the other one's row won: the screen is out of date.
            throw new ConcurrencyConflict;
        }
    }

    private function writeCrew(string $employeeId, ?string $siteId, string $date): void
    {
        DB::transaction(function () use ($employeeId, $siteId, $date): void {
            $row = DayCrewChange::where(['employee_id' => $employeeId, 'date' => $date])->lockForUpdate()->first();
            $home = $this->homeSiteOn($employeeId, $date);
            if ($siteId === $home) {
                $row?->delete();
            } elseif ($row === null) {
                DayCrewChange::create(['employee_id' => $employeeId, 'date' => $date, 'site_id' => $siteId]);
            } elseif ($row->site_id !== $siteId) {
                $row->site_id = $siteId;
                $row->save();
            }
        });
    }

    /**
     * A new day laborer, hired on $date and on $siteId's crew that day, with no home site and no pay row; $wage waits as a pending
     * proposal, and a null $wage makes none (the site workbook's blank proposed wage: HR sets the wage on the employee's page).
     *
     * $clientRequestId is the site manager's form id: its unique index makes a second identical request fail inside this transaction,
     * which then rolls back the employee too, and the caller replays the first answer (byClientRequest).
     *
     * @param  array{name_ar: string, gender: string, phone?: ?string, national_id?: ?string, residence_id?: ?string}  $identity
     * @return ($wage is string ? array{id: string, code: int, name_ar: string, proposal_id: string} : array{id: string, code: int, name_ar: string, proposal_id: null})
     */
    public function propose(array $identity, string $siteId, string $date, ?string $wage, ?string $clientRequestId = null): array
    {
        return DB::transaction(function () use ($identity, $siteId, $date, $wage, $clientRequestId): array {
            $code = EmployeeCodes::issueNext();
            // The national ID and residence only when given, so a site manager's proposal audits the same row as before.
            $optional = array_filter(['national_id' => $identity['national_id'] ?? null, 'residence_id' => $identity['residence_id'] ?? null], fn (?string $v) => $v !== null);
            $employee = Employee::create(['name_ar' => $identity['name_ar'], 'gender' => $identity['gender'], 'phone' => $identity['phone'] ?? null, ...$optional, 'hire_date' => $date, 'code' => $code]);
            $employee->typeChanges()->create(['type' => EmployeeType::DayLaborer->value, 'effective_from' => $date]);
            $employee->crewChanges()->create(['date' => $date, 'site_id' => $siteId]);
            $proposal = $wage === null ? null : EmployeeWageProposal::create([
                'employee_id' => $employee->id, 'daily_wage' => $wage, 'status' => WageProposalStatus::Pending,
                ...($clientRequestId === null ? [] : ['client_request_id' => $clientRequestId]),
            ]);

            return ['id' => (string) $employee->id, 'code' => $code, 'name_ar' => (string) $employee->name_ar, 'proposal_id' => $proposal === null ? null : (string) $proposal->id];
        });
    }

    /**
     * A site manager's new daily wage for a day laborer who already exists (Youssef, 2026-11-19, #79): a pending wage-change proposal
     * with their optional $reason, which HR approves from a date it picks or refuses. The route's employee.scope has already checked
     * the laborer is in $managerId's reach today and not archived. HR hears at once, with no amount (§9).
     *
     * $clientRequestId makes a retry return the first answer (`replayed`); a reused id with another laborer or wage is a 409.
     *
     * @return array{id: string, status: string, replayed: bool}
     */
    public function proposeWage(string $employeeId, string $wage, ?string $reason, ?string $clientRequestId, string $managerId, string $today): array
    {
        if ($clientRequestId !== null && ($first = $this->wageByClientRequest($clientRequestId, $employeeId, $wage, $managerId)) !== null) {
            return $first;
        }
        $laborer = $this->laborer($employeeId, $today);
        if ($laborer === null) {
            throw ValidationException::withMessages(['employee' => __('errors.not_a_day_laborer')]);
        }
        if (EmployeeWageProposal::where(['employee_id' => $employeeId, 'status' => WageProposalStatus::Pending])->exists()) {
            throw ValidationException::withMessages(['employee' => __('errors.wage_proposal_pending')]);
        }
        try {
            $p = DB::transaction(fn () => EmployeeWageProposal::create([
                'employee_id' => $employeeId, 'daily_wage' => $wage, 'status' => WageProposalStatus::Pending, 'kind' => WageProposalKind::WageChange,
                'reason' => $reason, ...($clientRequestId === null ? [] : ['client_request_id' => $clientRequestId]),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request won: the same form (its id) replays, another proposal for the laborer is the pending refusal.
            if ($clientRequestId !== null && str_contains($e->getMessage(), 'employee_wage_proposals_client_request')) {
                return $this->wageByClientRequest($clientRequestId, $employeeId, $wage, $managerId) ?? throw $e;
            }
            throw str_contains($e->getMessage(), 'employee_wage_proposals_one_pending') ? ValidationException::withMessages(['employee' => __('errors.wage_proposal_pending')]) : $e;
        }
        $siteId = $this->siteOn($laborer, $today);
        $site = $siteId === null ? null : $this->sites->names([$siteId])[$siteId] ?? null;
        foreach ($this->holders->userIds('employee.approve_wage.all') as $id) {
            $this->notifier->notifyOnce($id, 'wage_proposed', $today, [
                'employee_id' => $employeeId, 'site_name_ar' => (string) ($site['name_ar'] ?? ''), 'site_name_en' => $site['name_en'] ?? null,
            ], (string) $p->id);
        }

        return ['id' => (string) $p->id, 'status' => WageProposalStatus::Pending->value, 'replayed' => false];
    }

    /**
     * The day's crew changes by site, for HR's evening digest: who was added to a site, and who was taken off every crew.
     *
     * @return array{added: array<string, int>, removed: int}
     */
    public function changesOn(string $date): array
    {
        $rows = DayCrewChange::where('date', $date)->get(['site_id']);

        return [
            'added' => $rows->whereNotNull('site_id')->countBy(fn (DayCrewChange $c) => (string) $c->site_id)->all(),
            'removed' => $rows->whereNull('site_id')->count(),
        ];
    }

    /**
     * HR's pending proposals, oldest first, a page at a time.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function pending(int $perPage, int $page): array
    {
        $list = $this->page(EmployeeWageProposal::with(['employee.crewChanges', 'employee.siteAssignments', 'employee.payChanges'])->where('status', WageProposalStatus::Pending)->orderBy('id'), $perPage, $page);
        // Named in one query each, not per row: the site the laborer was added to, and the site manager who proposed the wage.
        $sites = $this->sites->names(array_values(array_filter(array_column($list['data'], 'site_id'))));
        $users = User::withTrashed()->whereKey(array_filter(array_column($list['data'], 'proposed_by')))->pluck('name', 'id');
        $list['data'] = array_map(function (array $r) use ($sites, $users): array {
            return [
                ...array_diff_key($r, ['site_id' => true]),
                'site' => $r['site_id'] === null ? null : $sites[$r['site_id']] ?? null,
                'proposed_by' => $r['proposed_by'] === null ? null : ['id' => $r['proposed_by'], 'name' => $users[$r['proposed_by']] ?? null],
            ];
        }, $list['data']);

        return $list;
    }

    /** @return array<string, mixed> a pending proposal as HR's list shows it; pending() names its site and proposer */
    private function row(Model $m): array
    {
        /** @var EmployeeWageProposal $m */
        $e = $m->employee;
        $change = $m->kind === WageProposalKind::WageChange;

        return [
            'id' => $m->id,
            'version' => $m->version,
            'kind' => $m->kind->value,
            'daily_wage' => $m->daily_wage,
            // a wage change: the daily wage in force today, beside the proposed one (HR only); a new laborer has none
            'current_wage' => $change && $e !== null ? $this->wageOn($e, now('Africa/Cairo')->toDateString()) : null,
            'reason' => $m->reason,
            'proposed_by' => $m->created_by,
            'created_at' => $m->created_at?->toIso8601String(),
            'site_id' => $this->proposalSite($m),
            // HR only (employee.approve_wage.all): the national ID the site manager typed, if any
            'employee' => ['id' => $m->employee_id, 'code' => (int) $e?->code, 'name_ar' => (string) $e?->name_ar, 'name_en' => $e?->name_en, 'gender' => $e?->gender, 'phone' => $e?->phone, 'national_id' => $e?->national_id, 'hire_date' => $e?->hire_date],
        ];
    }

    /**
     * HR decides a proposal; a decided or changed proposal is a 409. Approving a new laborer's wage writes the pay row from the hire date,
     * at HR's $wage or else the proposed one; approving a wage change (#79) writes it from HR's $from through EmployeeService::addPayChange,
     * which refuses a date in a locked period or one that already has a pay row. Refusing a wage change changes nothing on the laborer.
     * Refusing a new laborer's wage ends the laborer on $today (or keeps an earlier end) and takes them off every crew from then, and
     * off any earlier day with nothing recorded (owner, 2026-11-05): they are on no list, in no search, on no crew and get no new day.
     * Days already recorded stay; HR decides what to do with them. A refusal carries HR's $reason, and an approval keeps the wage it
     * approved (approved_wage), both for the proposing site manager's view of their request. Audited, with the actor and decided_at.
     *
     * @return array{proposed_by: ?string, employee_id: string} who to tell, and about whom
     */
    public function decide(string $proposalId, int $version, bool $approve, ?string $wage, string $hrId, string $today, ?string $reason = null, ?string $from = null): array
    {
        $decided = $this->decideInTransaction($proposalId, $version, $approve, $wage, $hrId, $today, $reason, $from);
        // After the commit, so a rolled-back decision tells nobody. Ids only, never the wage or the reason (§9; owner, 2026-11-19): the
        // site manager reads both on their request. notifyOnce keys on the proposal, so a retry adds nothing.
        // Only a site manager's own proposal: HR's site-workbook import also proposes, inside HR's request, and its importer has no
        // «مقترحات الأجور» to open (review of #72).
        $proposer = $decided['kind'] === WageProposalKind::WageChange ? 'employee.propose_wage.own' : 'employee.propose_laborer.own';
        if ($decided['proposed_by'] !== null && in_array($decided['proposed_by'], $this->holders->userIds($proposer), true)) {
            $this->notifier->notifyOnce($decided['proposed_by'], $approve ? 'wage_approved' : 'wage_refused', $today, ['employee_id' => $decided['employee_id'], 'proposal_id' => $proposalId], $proposalId);
        }

        return $decided;
    }

    /** @return array{proposed_by: ?string, employee_id: string, kind: WageProposalKind} */
    private function decideInTransaction(string $proposalId, int $version, bool $approve, ?string $wage, string $hrId, string $today, ?string $reason, ?string $from): array
    {
        return DB::transaction(function () use ($proposalId, $version, $approve, $wage, $hrId, $today, $reason, $from): array {
            $p = EmployeeWageProposal::lockForUpdate()->findOrFail($proposalId);
            if ($p->status !== WageProposalStatus::Pending || $p->version !== $version) {
                throw new ConcurrencyConflict;
            }
            $employee = Employee::lockForUpdate()->findOrFail($p->employee_id);
            $approved = $wage ?? (string) $p->daily_wage;
            if ($p->kind === WageProposalKind::WageChange) {
                if ($approve) {
                    if ($from === null) {
                        throw ValidationException::withMessages(['effective_from' => __('validation.required', ['attribute' => __('validation.attributes.effective_from')])]);
                    }
                    // still a day laborer, employed, on HR's date: a type change or a termination since the proposal refuses it
                    if ($this->laborer((string) $employee->id, $from) === null) {
                        throw ValidationException::withMessages(['effective_from' => __('errors.not_a_day_laborer_on_date')]);
                    }
                    $this->employees->addPayChange((string) $employee->id, ['effective_from' => $from, 'daily_wage' => $approved]);
                }
            } elseif ($approve) {
                $from = (string) ($employee->hire_date ?? $p->created_at?->toDateString());
                $employee->payChanges()->create(['effective_from' => $from, 'daily_wage' => $approved]);
            } else {
                // An earlier end HR already set stays; never before the hire date (PR #22 review).
                $employee->termination_date = max((string) $employee->hire_date, min($today, (string) ($employee->termination_date ?? $today)));
                $employee->save();
                // Off every crew from today, and off any earlier day with nothing recorded, so the yesterday window adds no new day.
                $recorded = DB::table('attendance_days')->where('employee_id', $employee->id)->whereNull('deleted_at')->pluck('date')->map(fn ($d) => (string) $d)->all();
                DayCrewChange::where('employee_id', $employee->id)->where(fn (Builder $q) => $q->where('date', '>=', $today)->orWhereNotIn('date', $recorded))
                    ->get()->each(fn (DayCrewChange $c) => $c->delete());
            }
            $p->fill([
                'status' => $approve ? WageProposalStatus::Approved : WageProposalStatus::Refused, 'decided_by' => $hrId, 'decided_at' => now(), 'version' => $version + 1,
                ...($approve ? ['approved_wage' => $approved] : ['refusal_reason' => $reason]),
                ...($approve && $p->kind === WageProposalKind::WageChange ? ['effective_from' => $from] : []),
            ]);
            $p->save();

            return ['proposed_by' => $p->created_by, 'employee_id' => (string) $p->employee_id, 'kind' => $p->kind];
        });
    }

    /**
     * The first answer to a site manager's "new day laborer" request with this form id, for a double tap or a retry; null when there is
     * none yet. Another user's id is never replayed (a 404 for them). A replay is the same request: when its name, wage or phone differ
     * from what was saved, it is refused (409 client_request_reused) instead of answering as if the correction was saved (review of #72).
     *
     * @param  array{name_ar?: string, daily_wage?: string, phone?: ?string}|null  $data  the replayed request
     * @return array{id: string, code: int, name_ar: string, proposal_id: string}|null
     */
    public function byClientRequest(string $clientRequestId, string $managerId, ?array $data = null): ?array
    {
        $p = EmployeeWageProposal::with('employee')->where('client_request_id', $clientRequestId)->first();
        if ($p === null) {
            return null;
        }
        if ($p->created_by !== $managerId || $p->employee === null) {
            throw new ModelNotFoundException;
        }
        if ($data !== null && (
            (isset($data['name_ar']) && $data['name_ar'] !== (string) $p->employee->name_ar)
            || (isset($data['daily_wage']) && bccomp(Decimal::of($data['daily_wage'], 'daily_wage'), Decimal::of((string) $p->daily_wage, 'daily_wage'), 6) !== 0)
            || (array_key_exists('phone', $data) && ($data['phone'] ?? null) !== $p->employee->phone)
        )) {
            throw new ClientRequestReused;
        }

        return ['id' => (string) $p->employee_id, 'code' => (int) $p->employee->code, 'name_ar' => (string) $p->employee->name_ar, 'proposal_id' => (string) $p->id];
    }

    /** @return array{id: string, code: int, name_ar: string, name_en: ?string} what the crew search shows of an employee */
    public function identityOf(string $employeeId): array
    {
        $e = Employee::withTrashed()->findOrFail($employeeId);

        return ['id' => (string) $e->id, 'code' => (int) $e->code, 'name_ar' => (string) $e->name_ar, 'name_en' => $e->name_en];
    }

    /**
     * The proposals $managerId made, newest first: each one's worker, status, the wage they proposed, and once decided, the wage HR
     * approved or HR's reason for refusing (owner, 2026-11-19: the proposing manager sees the outcome of their own proposal only).
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function mine(string $managerId, int $perPage, int $page): array
    {
        $p = EmployeeWageProposal::with(self::firstCrew())->where('created_by', $managerId)->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        return [
            // no figure in the list (Youssef, 2026-11-19): the amounts come with the opened request (mineOne), so the browser never holds them
            'data' => $this->named(array_values($p->getCollection()->map(fn (EmployeeWageProposal $m) => Arr::except($this->outcome($m), ['proposed_wage', 'approved_wage', 'refusal_reason']))->all())),
            'pagination' => [
                'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'total_pages' => $p->lastPage(),
                'has_next_page' => $p->hasMorePages(), 'has_previous_page' => $p->currentPage() > 1,
            ],
        ];
    }

    /** @return array<string, mixed> one of $managerId's proposals; 404 for anyone else's (an altered id never reaches it) */
    public function mineOne(string $managerId, string $proposalId): array
    {
        $p = EmployeeWageProposal::with(self::firstCrew())->where('created_by', $managerId)->findOrFail($proposalId);

        return $this->named([$this->outcome($p)])[0];
    }

    /** @return array<string, mixed> */
    private function outcome(EmployeeWageProposal $p): array
    {
        $e = $p->employee;

        return [
            'id' => (string) $p->id,
            'kind' => $p->kind->value,
            'status' => $p->status->value,
            'reason' => $p->reason,
            'effective_from' => $p->status === WageProposalStatus::Approved ? $p->effective_from : null,
            'proposed_wage' => (string) $p->daily_wage,
            'approved_wage' => $p->status === WageProposalStatus::Approved ? $p->approved_wage : null,
            'refusal_reason' => $p->status === WageProposalStatus::Refused ? $p->refusal_reason : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'decided_at' => $p->decided_at?->toIso8601String(),
            'site_id' => $this->proposalSite($p),
            'employee' => ['id' => (string) $p->employee_id, 'code' => (int) $e?->code, 'name_ar' => (string) $e?->name_ar, 'name_en' => $e?->name_en],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> each row's site named (one query), site_id dropped
     */
    private function named(array $rows): array
    {
        $sites = $this->sites->names(array_values(array_filter(array_column($rows, 'site_id'))));

        return array_map(fn (array $r) => [...array_diff_key($r, ['site_id' => true]), 'site' => $r['site_id'] === null ? null : $sites[$r['site_id']] ?? null], $rows);
    }

    /**
     * The site a proposal is shown at: a new laborer's first crew (the site they were added to), or a wage change's laborer's site on the
     * day it was proposed. Needs the employee's crewChanges and siteAssignments loaded.
     */
    private function proposalSite(EmployeeWageProposal $p): ?string
    {
        $e = $p->employee;
        if ($e === null) {
            return null;
        }
        if ($p->kind === WageProposalKind::WageChange) {
            return $this->siteOn($e, (string) $p->created_at?->setTimezone('Africa/Cairo')->toDateString());
        }
        $first = $e->crewChanges->sortBy('date')->first();

        return $first?->site_id === null ? null : (string) $first->site_id;
    }

    /** The daily wage in force on $date, from the loaded payChanges. */
    private function wageOn(Employee $e, string $date): ?string
    {
        $rows = $e->payChanges->map(fn ($c) => ['effective_from' => (string) $c->effective_from, 'daily_wage' => $c->daily_wage])->all();
        $wage = EffectiveDated::at($rows, $date)['daily_wage'] ?? null;

        return $wage === null ? null : (string) $wage;
    }

    /**
     * The first answer to a site manager's wage-change form with this id, or null when there is none yet. Another manager's id is a 404;
     * the same id for another laborer or another wage is a 409 (client_request_reused).
     *
     * @return array{id: string, status: string, replayed: bool}|null
     */
    private function wageByClientRequest(string $clientRequestId, string $employeeId, string $wage, string $managerId): ?array
    {
        $p = EmployeeWageProposal::where('client_request_id', $clientRequestId)->first();
        if ($p === null) {
            return null;
        }
        if ($p->created_by !== $managerId) {
            throw new ModelNotFoundException;
        }
        if ($p->kind !== WageProposalKind::WageChange || $p->employee_id !== $employeeId
            || bccomp(Decimal::of($wage, 'daily_wage'), Decimal::of((string) $p->daily_wage, 'daily_wage'), 6) !== 0) {
            throw new ClientRequestReused;
        }

        return ['id' => (string) $p->id, 'status' => $p->status->value, 'replayed' => true];
    }

    private function laborer(string $employeeId, string $date): ?Employee
    {
        $e = Employee::with(['typeChanges', 'siteAssignments', 'crewChanges'])->find($employeeId);
        if ($e === null || ($e->hire_date !== null && $e->hire_date > $date) || ($e->termination_date !== null && $e->termination_date < $date)) {
            return null;
        }
        $types = $e->typeChanges->map(fn ($t) => ['type' => (string) $t->type, 'effective_from' => (string) $t->effective_from])->all();

        return (EffectiveDated::at($types, $date)['type'] ?? null) === EmployeeType::DayLaborer->value ? $e : null;
    }

    private function siteOn(Employee $e, string $date): ?string
    {
        $change = $e->crewChanges->first(fn (DayCrewChange $c) => (string) $c->date === $date);
        if ($change !== null) {
            return $change->site_id === null ? null : (string) $change->site_id;
        }

        return $this->homeOf($e, $date);
    }

    private function homeSiteOn(string $employeeId, string $date): ?string
    {
        $e = Employee::with('siteAssignments')->find($employeeId);

        return $e === null ? null : $this->homeOf($e, $date);
    }

    private function homeOf(Employee $e, string $date): ?string
    {
        $sites = $e->siteAssignments->map(fn (EmployeeSiteAssignment $s) => ['site_id' => (string) $s->site_id, 'effective_from' => (string) $s->effective_from])->all();
        $site = EffectiveDated::at($sites, $date)['site_id'] ?? null;

        return $site === null ? null : (string) $site;
    }
}
