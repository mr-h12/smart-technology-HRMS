<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeArchived;
use App\Modules\Employees\Domain\EmployeeCodeTaken;
use App\Modules\Employees\Domain\EmployeeHasOpenItems;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Domain\PayMethod;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeDocument;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeManagerAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeePayChange;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeWageProposal;
use App\Modules\Identity\Application\Administration\UserAdministration;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Sites\Application\SiteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Employees and their dated type/pay history. Methods return arrays shaped for the API. */
final class EmployeeService
{
    /** user_id: the account link reaches HR and the Developer only through show()'s site_manager block. */
    private const HIDDEN = ['created_by', 'updated_by', 'deleted_at', 'user_id'];

    private const CURRENT_SITE = '(select s.site_id from employee_site_assignments s where s.employee_id = employees.id and s.deleted_at is null and s.effective_from <= ? order by s.effective_from desc limit 1)';

    private const PAY_FIELDS = ['effective_from', 'salary', 'raise', 'insured_basic', 'daily_wage', 'shift_start', 'shift_end'];

    /** Coding standard §9: kept out of own-scoped (manager-facing) responses. */
    private const SENSITIVE = ['national_id', 'insurance_no', 'bank_account', 'hr_notes'];

    public function __construct(
        private readonly AuthorizeAction $authorize,
        private readonly UserAdministration $users,
        private readonly SiteService $sites,
    ) {}

    /**
     * @param  array{type?: string, site_id?: string, search?: string, per_page?: int, archived?: bool}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters, ?string $ownerId = null): array
    {
        $query = $this->visibleTo($ownerId, $filters['archived'] ?? false)->select('employees.*')->selectRaw(Employee::TYPE_ON.' as current_type', [self::today()])->orderBy('code');

        if (isset($filters['type'])) {
            $query->whereRaw(Employee::TYPE_ON.' = ?', [self::today(), $filters['type']]);
        }

        if (isset($filters['site_id'])) {
            // an office employee's site only lists them in HR's attendance filter: this list is the site's workers
            $query->whereRaw(self::CURRENT_SITE.' = ?', [self::today(), $filters['site_id']])
                ->whereRaw(Employee::TYPE_ON.' in (?, ?)', [self::today(), EmployeeType::PermanentWorker->value, EmployeeType::DayLaborer->value]);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->matching($filters['search'], $ownerId === null);
        }

        $page = $query->paginate(min(max($filters['per_page'] ?? 20, 1), 100));

        return [
            'data' => array_values($page->getCollection()->map(fn (Employee $e) => $this->summary($e, $ownerId === null))->all()),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_next_page' => $page->hasMorePages(),
                'has_previous_page' => $page->currentPage() > 1,
            ],
        ];
    }

    /** @return array<string, int> `total` plus one count per employee type, by the type in force today, over the list's scope of active employees; `total` also counts employees with no type in force yet, so it can exceed the sum of the types */
    public function countsByType(?string $ownerId = null): array
    {
        $counts = $this->visibleTo($ownerId)->toBase()
            ->selectRaw(Employee::TYPE_ON.' as type, count(*) as n', [self::today()])
            ->groupBy('type')->pluck('n', 'type');
        $byType = array_map(fn (EmployeeType $t) => (int) ($counts[$t->value] ?? 0), array_column(EmployeeType::cases(), null, 'value'));

        return ['total' => (int) $counts->sum(), ...$byType];
    }

    /**
     * The archive is the employees whose termination date has passed (Employee::isArchived).
     *
     * @return Builder<Employee> live employees, active or archived, or only those a manager reaches today
     */
    private function visibleTo(?string $ownerId, bool $archived = false): Builder
    {
        $query = Employee::query()->archived($archived);

        return $ownerId === null ? $query : EmployeeReach::apply($query, $ownerId, self::today());
    }

    /**
     * @param  array<string, mixed>  $data  identity fields plus type, effective_from and pay fields
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->assertBank($data);
        $type = EmployeeType::from($data['type']);
        $pay = $this->payRow($data);
        $this->assertPay($type, $pay);

        $id = DB::transaction(function () use ($data, $type, $pay): string {
            $code = EmployeeCodes::issueNext();

            $employee = Employee::create([...Arr::except($data, ['type', ...self::PAY_FIELDS]), 'code' => $code]);
            $employee->typeChanges()->create(['type' => $type->value, 'effective_from' => $data['effective_from']]);
            $employee->payChanges()->create($pay);

            return (string) $employee->getKey();
        });

        return $this->show($id);
    }

    /**
     * @param  string|null  $ownerId  set for own-scoped callers: pay and sensitive identity fields are left out
     * @return array<string, mixed> documents carry their file fields when seesFiles()
     */
    public function show(string $id, ?string $ownerId = null): array
    {
        $e = Employee::with(['typeChanges', 'payChanges', 'documents.uploader', 'managerAssignments.manager', 'siteAssignments.site'])->findOrFail($id);
        $types = $e->typeChanges->sortBy('effective_from')->values()->map(fn ($t) => $this->row($t))->all();
        $pays = $e->payChanges->sortBy('effective_from')->values()->map(fn ($p) => $this->row($p))->all();
        $managers = $e->managerAssignments->sortBy('effective_from')->values()->map(fn (EmployeeManagerAssignment $m) => [
            'id' => $m->id,
            'manager_id' => $m->manager_id,
            'manager_name' => $m->manager?->name,
            'effective_from' => $m->effective_from,
        ])->all();
        $sites = $e->siteAssignments->sortBy('effective_from')->values()->map(fn (EmployeeSiteAssignment $s) => [
            'id' => $s->id,
            'site_id' => $s->site_id,
            'site_name_ar' => $s->site?->name_ar,
            'site_name_en' => $s->site?->name_en,
            'effective_from' => $s->effective_from,
        ])->all();
        $today = self::today();
        $manager = EffectiveDated::at($managers, $today);
        $site = EffectiveDated::at($sites, $today);
        $full = $ownerId === null;
        $kinds = array_flip(array_column(DocumentKind::cases(), 'value'));
        $files = $this->seesFiles($id);

        return [
            ...$this->summary($e, $full),
            'archived' => Employee::isArchived($e->termination_date),
            'current_type' => EffectiveDated::at($types, $today)['type'] ?? null,
            ...($full ? ['current_pay' => EffectiveDated::at($pays, $today), 'pay_changes' => $pays] : []),
            'type_changes' => $types,
            'current_manager' => $manager === null ? null : ['id' => $manager['manager_id'], 'name' => $manager['manager_name']],
            'manager_assignments' => $managers,
            'current_site' => $site === null ? null : ['id' => $site['site_id'], 'name_ar' => $site['site_name_ar'], 'name_en' => $site['site_name_en']],
            'site_assignments' => $sites,
            ...($this->seesAccounts() ? ['site_manager' => $e->user_id === null ? null : $this->siteManager((string) $e->user_id)] : []),
            'documents' => $e->documents->sortBy([
                fn (EmployeeDocument $a, EmployeeDocument $b) => $kinds[$a->kind] <=> $kinds[$b->kind],
                ['id', 'desc'], // UUIDv7: newest first
            ])->values()->map(fn (EmployeeDocument $d) => EmployeeDocuments::row($d, $files))->all(),
        ];
    }

    /**
     * An archived employee changes only its termination date and reason (reactivate or correct; owner, 2026-11-11), judged on changed
     * values because the profile form sends every identity field on each save. Setting or changing a termination date waits until the
     * employee's open items are decided; clearing it never does. A termination date that archives the employee deactivates their
     * linked account at once; clearing it does not reactivate the account.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        DB::transaction(function () use ($id, $data): void {
            $employee = Employee::findOrFail($id);
            $archived = Employee::isArchived($employee->termination_date);
            $employee->fill($data);
            if ($archived && array_diff(array_keys($employee->getDirty()), ['termination_date', 'termination_reason']) !== []) {
                throw new EmployeeArchived;
            }
            if ($employee->isDirty('termination_date') && $employee->termination_date !== null && ($items = $this->openItems($id)) !== []) {
                throw new EmployeeHasOpenItems($items);
            }
            if ($employee->isDirty('termination_date')) {
                // The termination date is the last day employed: the days after the earlier of the old and new dates switch.
                $old = $employee->getOriginal('termination_date');
                $new = $employee->termination_date;
                $this->assertUnlocked($employee, Carbon::parse((string) min($old ?? $new, $new ?? $old))->addDay()->toDateString(), 'termination_date');
            }
            $this->assertBank($employee->only(['pay_method', 'bank_account']));
            $employee->save();
            if ($employee->wasChanged('termination_date')) {
                $this->deactivateArchivedAccounts($id);
            }
        });

        return $this->show($id);
    }

    /**
     * Deactivates the account linked to each archived employee, or to $employeeId only (owner, 2026-11-12). The daily job sweeps them
     * all, so a termination date that passes overnight, or a missed night, is caught up; an inactive account is left as is.
     */
    public function deactivateArchivedAccounts(?string $employeeId = null): void
    {
        Employee::archived()->whereNotNull('user_id')->when($employeeId !== null, fn (Builder $q) => $q->whereKey($employeeId))
            ->orderBy('id')->get(['id', 'user_id'])
            ->each(fn (Employee $e) => $this->users->deactivateForArchivedEmployee((string) $e->user_id, (string) $e->id));
    }

    /**
     * HR renumbers an employee. A deleted employee's code is never reused; a live holder's (active or archived) is refused unless
     * $swap, which exchanges the two codes in one transaction, each save audited.
     *
     * @return array<string, mixed>
     */
    public function changeCode(string $id, int $code, bool $swap): array
    {
        $employee = Employee::findOrFail($id);
        if ($employee->code === $code) {
            return $this->show($id);
        }
        // Numbers are never reissued and a typo cannot jump the numbering (owner, 2026-11-03).
        if ($code > ($highest = EmployeeCodes::highestIssued())) {
            throw ValidationException::withMessages(['code' => __('errors.code_above_highest', ['max' => $highest])]);
        }
        try {
            DB::transaction(function () use ($id, $code, $swap): void {
                $holderId = Employee::withTrashed()->where('code', $code)->value('id');
                // One query in id order: two opposite swaps lock their two rows in the same order, so they cannot deadlock.
                $locked = Employee::withTrashed()->whereIn('id', array_filter([$id, $holderId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $employee = $locked->get($id);
                if ($employee === null || $employee->trashed()) {
                    throw (new ModelNotFoundException)->setModel(Employee::class, [$id]);
                }
                if ($employee->code === $code) {
                    return;
                }
                // Re-checked under the lock: the holder looked up above may have moved off the code or been deleted meanwhile.
                $holder = $locked->first(fn (Employee $e) => $e->code === $code);
                if ($holder === null && Employee::withTrashed()->where('code', $code)->exists()) {
                    throw ValidationException::withMessages(['code' => __('errors.code_changed_meanwhile')]);   // held by a row not locked here
                }
                if ($holder?->trashed()) {
                    throw ValidationException::withMessages(['code' => __('errors.code_deleted_holder')]);
                }
                // 0 marks the CEO (owner, 2026-11-06, R23): it is given only while free, and never swapped onto someone else.
                if ($holder !== null && ($code === 0 || $employee->code === 0)) {
                    throw ValidationException::withMessages(['code' => __('errors.code_zero_ceo')]);
                }
                if ($holder !== null && ! $swap) {
                    throw new EmployeeCodeTaken($code, (string) $holder->name_ar, $holder->name_en);
                }
                // The unique is checked once both rows hold their new codes (migration 2026_11_03_000002).
                DB::statement('SET CONSTRAINTS employees_code_unique DEFERRED');
                $old = $employee->code;
                $employee->update(['code' => $code]);
                $holder?->update(['code' => $old]);
                DB::statement('SET CONSTRAINTS employees_code_unique IMMEDIATE');
            });
        } catch (UniqueConstraintViolationException) {
            // Another write took the code between the check and the save.
            throw ValidationException::withMessages(['code' => __('errors.code_changed_meanwhile')]);
        }

        return $this->show($id);
    }

    public function delete(string $id): void
    {
        Employee::findOrFail($id)->delete();
    }

    /**
     * @param  array{type: string, effective_from: string}  $data
     * @return array<string, mixed>
     */
    public function addTypeChange(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $this->assertFreeDate($employee->typeChanges(), $data['effective_from']);
        // The new type's period start; the old type's last period ends the day before (spec 2026-11-09, open point default).
        $new = EmployeeType::from($data['type']);
        if (Period::containing($new, $data['effective_from'])->start !== $data['effective_from']) {
            throw ValidationException::withMessages(['effective_from' => __('errors.type_change_not_period_start')]);
        }
        DB::transaction(function () use ($employee, $data, $new): void {
            $this->assertUnlocked($employee, $data['effective_from'], new: $new);
            $employee->typeChanges()->create($data);
        });

        return $this->show($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addPayChange(string $id, array $data): array
    {
        $employee = Employee::findOrFail($id);
        $this->assertFreeDate($employee->payChanges(), $data['effective_from']);

        $types = $employee->typeChanges->map(fn ($t) => $this->row($t))->all();
        $type = EffectiveDated::at($types, $data['effective_from']);
        if ($type === null) {
            throw ValidationException::withMessages(['effective_from' => __('errors.no_type_on_date')]);
        }
        // M2: a row is a full snapshot, so it starts from the row in force on its date and takes only the fields sent (a blank sent clears).
        // Only the pay fields of the type in force are kept: a row after a type change does not inherit the other type's wage.
        $kept = [...($type['type'] === EmployeeType::DayLaborer->value ? ['daily_wage'] : ['salary', 'raise', 'insured_basic']), 'shift_start', 'shift_end'];
        $inForce = Arr::only(EffectiveDated::at($employee->payChanges->map(fn ($p) => $this->row($p))->all(), $data['effective_from']) ?? [], $kept);
        $pay = $this->payRow([...$inForce, ...$data]);
        $this->assertPay(EmployeeType::from($type['type']), $pay);

        DB::transaction(function () use ($employee, $data, $pay): void {
            $this->assertUnlocked($employee, $data['effective_from']);
            $employee->payChanges()->create($pay);
        });

        return $this->show($id);
    }

    /** Whether this employee is archived today; false for an unknown id. */
    public function isArchived(string $employeeId): bool
    {
        return Employee::isArchived(Employee::whereKey($employeeId)->value('termination_date'));
    }

    /** Whether the employee is in the user's reach on $date (default today). */
    public function isManagedBy(string $employeeId, string $userId, ?string $date = null): bool
    {
        return EmployeeReach::reaches($employeeId, $userId, $date ?? self::today());
    }

    /** The signed-in user sees this employee's document file fields and notes: employee_document.view at `all`, or `own` with the employee in reach today. */
    public function seesFiles(string $employeeId): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        $view = $this->authorize->decide($user, 'employee_document.view');

        return $view->allows(Scope::All) || ($view->allows(Scope::Own) && $this->isManagedBy($employeeId, (string) $user->getAuthIdentifier()));
    }

    /**
     * @param  array{manager_id: string, effective_from: string}  $data
     * @return array<string, mixed>
     */
    public function assignManager(string $id, array $data): array
    {
        $this->addManagerAssignment(Employee::findOrFail($id), $data);

        return $this->show($id);
    }

    /**
     * HR or the Developer makes the employee a site manager in one step (owner, 2026-11-12): creates the account with the site-manager
     * role, or links an existing site-manager account, then sets its sites and adds engineers. Any refusal leaves nothing behind.
     *
     * @param  array<string, mixed>  $data  user_id, or email and password; site_ids, engineer_ids, effective_from
     * @return array<string, mixed>
     */
    public function makeSiteManager(string $id, array $data): array
    {
        try {
            DB::transaction(function () use ($id, $data): void {
                $employee = Employee::lockForUpdate()->findOrFail($id);
                if ($employee->user_id !== null) {
                    throw ValidationException::withMessages(['employee' => __('errors.already_site_manager')]);
                }
                $userId = isset($data['user_id']) ? $this->linkable((string) $data['user_id']) : (string) $this->users->create([
                    'name' => $employee->name_ar, 'email' => $data['email'] ?? null, 'password' => $data['password'] ?? null, 'role_id' => $this->sites->managerRoleId(),
                ])['id'];
                $employee->update(['user_id' => $userId]);   // Audited: UPDATED on employees with the before/after link
                $this->setTeam($employee, $userId, $data);
            });
        } catch (UniqueConstraintViolationException $e) {
            // employees_user_id_unique: another request linked the same account meanwhile.
            throw str_contains($e->getMessage(), 'employees_user_id_unique') ? ValidationException::withMessages(['user_id' => __('errors.account_linked')]) : $e;
        }

        return $this->show($id);
    }

    /**
     * Changes a site manager's sites and adds engineers later, from the same dialog.
     *
     * @param  array<string, mixed>  $data  site_ids, engineer_ids, effective_from
     * @return array<string, mixed>
     */
    public function updateSiteManager(string $id, array $data): array
    {
        DB::transaction(function () use ($id, $data): void {
            $employee = Employee::lockForUpdate()->whereNotNull('user_id')->findOrFail($id);
            $userId = (string) $employee->user_id;
            if (! in_array($userId, $this->sites->managerCandidateIds(), true)) {
                throw ValidationException::withMessages(['account' => __('errors.not_site_manager_account')]);
            }
            $this->setTeam($employee, $userId, $data);
        });

        return $this->show($id);
    }

    /**
     * Moves a worker, or an office employee, to a home site from a date. An office employee's site only lists them at it in HR's filter (owner, 2026-11-08); engineers have none. With returns_on, a second row puts them back on the site they had the day before.
     *
     * @param  array{site_id: string, effective_from: string, returns_on?: ?string}  $data
     * @return array<string, mixed>
     */
    public function assignSite(string $id, array $data): array
    {
        DB::transaction(function () use ($id, $data): void {
            $employee = Employee::findOrFail($id);
            $from = $data['effective_from'];
            $type = EffectiveDated::at($employee->typeChanges->map(fn ($t) => $this->row($t))->all(), $from)['type'] ?? null;
            if (! in_array($type, [EmployeeType::PermanentWorker->value, EmployeeType::DayLaborer->value, EmployeeType::Office->value], true)) {
                throw ValidationException::withMessages(['effective_from' => __('errors.home_site_not_allowed')]);
            }
            $this->assertFreeDate($employee->siteAssignments(), $from);
            $this->assertUnlocked($employee, $from);

            $rows = [['site_id' => $data['site_id'], 'effective_from' => $from]];
            $returnsOn = $data['returns_on'] ?? null;
            if ($returnsOn !== null) {
                $previous = $employee->siteAssignments()->where('effective_from', '<', $from)->orderByDesc('effective_from')->value('site_id');
                if ($previous === null) {
                    throw ValidationException::withMessages(['returns_on' => __('errors.no_previous_site')]);
                }
                if ($employee->siteAssignments()->where('effective_from', '>', $from)->where('effective_from', '<=', $returnsOn)->exists()) {
                    throw ValidationException::withMessages(['returns_on' => __('errors.later_move_inside')]);
                }
                $rows[] = ['site_id' => $previous, 'effective_from' => $returnsOn];
            }

            $employee->siteAssignments()->createMany($rows);
        });

        return $this->show($id);
    }

    /** An existing account to link: a site-manager account linked to no other live employee (employees_user_id_unique is the last guard). */
    private function linkable(string $userId): string
    {
        if (! in_array($userId, $this->sites->managerCandidateIds(), true)) {
            throw ValidationException::withMessages(['user_id' => __('errors.not_site_manager_account')]);
        }
        if (Employee::where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('errors.account_linked')]);
        }

        return $userId;
    }

    /**
     * Sets the account's sites when site_ids is sent (absent keeps them), then assigns each ticked engineer to it from effective_from.
     * It only adds: employee_manager_assignments has no end date, so an engineer leaves through a later assignment to someone else.
     *
     * @param  array<string, mixed>  $data
     */
    private function setTeam(Employee $manager, string $userId, array $data): void
    {
        if (isset($data['site_ids'])) {
            $this->sites->setSitesOf($userId, $data['site_ids']);
        }
        $from = (string) ($data['effective_from'] ?? '');
        foreach ($data['engineer_ids'] ?? [] as $engineerId) {
            $engineer = Employee::with(['typeChanges', 'managerAssignments'])->findOrFail((string) $engineerId);
            $type = EffectiveDated::at($engineer->typeChanges->map(fn ($t) => $this->row($t))->all(), $from)['type'] ?? null;
            if (Employee::isArchived($engineer->termination_date) || $engineer->is($manager) || $type !== EmployeeType::Engineer->value) {
                throw ValidationException::withMessages(['engineer_ids' => __('errors.not_an_engineer', ['name' => $engineer->name_ar])]);
            }
            $managers = $engineer->managerAssignments->map(fn (EmployeeManagerAssignment $m) => ['manager_id' => $m->manager_id, 'effective_from' => $m->effective_from])->all();
            if ((EffectiveDated::at($managers, $from)['manager_id'] ?? null) === $userId) {
                continue;
            }
            try {
                $this->addManagerAssignment($engineer, ['manager_id' => $userId, 'effective_from' => $from]);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['engineer_ids' => __('errors.engineer_refused', [
                    'name' => $engineer->name_ar, 'reason' => collect($e->errors())->flatten()->first(),
                ])]);
            }
        }
    }

    /** @param  array{manager_id: string, effective_from: string}  $data */
    private function addManagerAssignment(Employee $employee, array $data): void
    {
        $this->assertFreeDate($employee->managerAssignments(), $data['effective_from']);
        DB::transaction(function () use ($employee, $data): void {
            $this->assertUnlocked($employee, $data['effective_from']);
            $employee->managerAssignments()->create($data);
        });
    }

    /** The signed-in user sees employees' accounts: user.view at `all` (HR and the Developer; never the CEO or a site manager). */
    private function seesAccounts(): bool
    {
        $user = Auth::user();

        return $user !== null && $this->authorize->decide($user, 'user.view')->allows(Scope::All);
    }

    /** @return array<string, mixed> the account's id, email, is_active and role, its sites, and the engineers it reaches today */
    private function siteManager(string $userId): array
    {
        $today = self::today();
        $engineers = EmployeeReach::ids($userId, $today)->whereRaw(Employee::TYPE_ON.' = ?', [$today, EmployeeType::Engineer->value])->orderBy('code')->pluck('id');

        return [
            ...Arr::only($this->users->show($userId), ['id', 'email', 'is_active', 'role']),
            'site_ids' => $this->sites->idsManagedBy($userId),
            'engineer_ids' => array_values($engineers->map(fn (mixed $id) => (string) $id)->all()),
        ];
    }

    /**
     * Each module's open items for the employee (EmployeeOpenItems listeners: Leave's pending and escalated requests), then the pending wage proposals.
     *
     * @return list<array<string, string>>
     */
    private function openItems(string $id): array
    {
        $items = array_merge(...array_values(array_filter(event(new EmployeeOpenItems($id)) ?? [], 'is_array')));
        foreach (EmployeeWageProposal::where(['employee_id' => $id, 'status' => WageProposalStatus::Pending])->orderBy('id')->pluck('id') as $proposal) {
            $items[] = ['kind' => 'wage_proposal', 'id' => (string) $proposal];
        }

        return array_values($items);
    }

    private static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }

    /** @param  array<string, mixed>  $data */
    private function assertBank(array $data): void
    {
        if (($data['pay_method'] ?? PayMethod::Cash->value) === PayMethod::Bank->value && blank($data['bank_account'] ?? null)) {
            throw ValidationException::withMessages(['bank_account' => __('errors.bank_account_required')]);
        }
    }

    /** @param  array<string, mixed>  $pay */
    private function assertPay(EmployeeType $type, array $pay): void
    {
        $need = $type === EmployeeType::DayLaborer ? 'daily_wage' : 'salary';
        if (blank($pay[$need] ?? null)) {
            throw ValidationException::withMessages([$need => __('errors.pay_field_required', [
                'field' => __('validation.attributes.'.$need),
                'type' => __('errors.employee_type.'.$type->value),
            ])]);
        }
    }

    /**
     * M1: a dated row governs the days from its date on, so a lock ending on or after the date, of the type in force there or of any type
     * the employee holds after it (every type when none is in force there), or of the $new type, refuses it, as FareService does. Call
     * inside the write's transaction (PeriodGuard::lockedFrom).
     */
    private function assertUnlocked(Employee $employee, string $date, string $field = 'effective_from', ?EmployeeType $new = null): void
    {
        $rows = $employee->typeChanges->map(fn ($t) => $this->row($t))->all();
        $old = EffectiveDated::at($rows, $date)['type'] ?? null;
        $later = array_column(array_filter($rows, fn (array $r) => $r['effective_from'] > $date), 'type');
        $types = $old === null ? EmployeeType::cases() : [...array_map(EmployeeType::from(...), [$old, ...$later]), ...array_filter([$new])];
        if (PeriodGuard::lockedFrom($date, ...$types)) {
            throw ValidationException::withMessages([$field => __('errors.dated_change_period_locked')]);
        }
    }

    /** @param  \Illuminate\Database\Eloquent\Relations\HasMany<*, *>  $relation */
    private function assertFreeDate($relation, string $date): void
    {
        if ($relation->where('effective_from', $date)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payRow(array $data): array
    {
        $pay = Arr::only($data, self::PAY_FIELDS);
        $pay['raise'] = blank($pay['raise'] ?? null) ? '0' : $pay['raise'];

        return $pay;
    }

    /** @return array<string, mixed> */
    private function summary(Employee $e, bool $full): array
    {
        return Arr::except($e->toArray(), [...self::HIDDEN, 'type_changes', 'pay_changes', 'documents', 'manager_assignments', 'site_assignments', ...($full ? [] : self::SENSITIVE)]);
    }

    /** @return array<string, mixed> */
    private function row(EmployeeTypeChange|EmployeePayChange $m): array
    {
        return Arr::except($m->toArray(), [...self::HIDDEN, 'employee_id']);
    }
}
