<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeWageProposal;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Application\SiteService;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private readonly SiteService $sites) {}

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
     * A new day laborer, hired on $date and on $siteId's crew that day, with no home site and no pay row; $wage waits as a pending proposal.
     *
     * @param  array{name_ar: string, gender: string, phone?: ?string}  $identity
     * @return array{id: string, code: int, name_ar: string, proposal_id: string}
     */
    public function propose(array $identity, string $siteId, string $date, string $wage): array
    {
        return DB::transaction(function () use ($identity, $siteId, $date, $wage): array {
            $code = EmployeeCodes::issueNext();
            $employee = Employee::create(['name_ar' => $identity['name_ar'], 'gender' => $identity['gender'], 'phone' => $identity['phone'] ?? null, 'hire_date' => $date, 'code' => $code]);
            $employee->typeChanges()->create(['type' => EmployeeType::DayLaborer->value, 'effective_from' => $date]);
            $employee->crewChanges()->create(['date' => $date, 'site_id' => $siteId]);
            $proposal = EmployeeWageProposal::create(['employee_id' => $employee->id, 'daily_wage' => $wage, 'status' => WageProposalStatus::Pending]);

            return ['id' => (string) $employee->id, 'code' => $code, 'name_ar' => (string) $employee->name_ar, 'proposal_id' => (string) $proposal->id];
        });
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
        $list = $this->page(EmployeeWageProposal::with('employee.crewChanges')->where('status', WageProposalStatus::Pending)->orderBy('id'), $perPage, $page);
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
        // the crew the site manager added them to: the change on their first day
        $first = $e?->crewChanges->sortBy('date')->first();

        return [
            'id' => $m->id,
            'version' => $m->version,
            'daily_wage' => $m->daily_wage,
            'proposed_by' => $m->created_by,
            'created_at' => $m->created_at?->toIso8601String(),
            'site_id' => $first?->site_id === null ? null : (string) $first->site_id,
            'employee' => ['id' => $m->employee_id, 'code' => (int) $e?->code, 'name_ar' => (string) $e?->name_ar, 'name_en' => $e?->name_en, 'gender' => $e?->gender, 'phone' => $e?->phone, 'hire_date' => $e?->hire_date],
        ];
    }

    /**
     * HR decides a proposal; a decided or changed proposal is a 409. Approving writes the pay row from the hire date, at HR's $wage or
     * else the proposed one. Refusing ends the laborer on $today (or keeps an earlier end) and takes them off every crew from then, and
     * off any earlier day with nothing recorded (owner, 2026-11-05): they are on no list, in no search, on no crew and get no new day.
     * Days already recorded stay; HR decides what to do with them. Audited.
     */
    public function decide(string $proposalId, int $version, bool $approve, ?string $wage, string $hrId, string $today): void
    {
        DB::transaction(function () use ($proposalId, $version, $approve, $wage, $hrId, $today): void {
            $p = EmployeeWageProposal::lockForUpdate()->findOrFail($proposalId);
            if ($p->status !== WageProposalStatus::Pending || $p->version !== $version) {
                throw new ConcurrencyConflict;
            }
            $employee = Employee::lockForUpdate()->findOrFail($p->employee_id);
            if ($approve) {
                $from = (string) ($employee->hire_date ?? $p->created_at?->toDateString());
                $employee->payChanges()->create(['effective_from' => $from, 'daily_wage' => $wage ?? (string) $p->daily_wage]);
            } else {
                // An earlier end HR already set stays; never before the hire date (PR #22 review).
                $employee->termination_date = max((string) $employee->hire_date, min($today, (string) ($employee->termination_date ?? $today)));
                $employee->save();
                // Off every crew from today, and off any earlier day with nothing recorded, so the yesterday window adds no new day.
                $recorded = DB::table('attendance_days')->where('employee_id', $employee->id)->whereNull('deleted_at')->pluck('date')->map(fn ($d) => (string) $d)->all();
                DayCrewChange::where('employee_id', $employee->id)->where(fn (Builder $q) => $q->where('date', '>=', $today)->orWhereNotIn('date', $recorded))
                    ->get()->each(fn (DayCrewChange $c) => $c->delete());
            }
            $p->fill(['status' => $approve ? WageProposalStatus::Approved : WageProposalStatus::Refused, 'decided_by' => $hrId, 'decided_at' => now(), 'version' => $version + 1]);
            $p->save();
        });
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
