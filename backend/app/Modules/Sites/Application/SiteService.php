<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sites (work places and leave "sites") and their dated allowance rules. */
final class SiteService
{
    use Paginates;

    // A site manager is an account whose role sees only its own sites (CLAUDE.md: permissions by name, never by role).
    // One that also sees every site is never narrowed by its sites (RequestScope: `all` wins), so it is not offered.

    public function __construct(private readonly PermissionHolders $holders) {}

    /**
     * @param  array{kind?: string, active?: string, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters, ?string $ownerId = null): array
    {
        $query = $this->visibleTo($ownerId)->orderBy('name_ar');
        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (isset($filters['active'])) {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $this->page($query, $filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $site = new Site($data);
        $this->assertLeaveCode($site);
        $site->save();

        return $this->show((string) $site->getKey());
    }

    /** @return array<string, mixed> */
    public function show(string $id, ?string $ownerId = null): array
    {
        $site = $this->visibleTo($ownerId)->with(['allowanceRules', 'managers'])->findOrFail($id);

        return [
            ...$this->row($site),
            'managers' => $site->managers->sortBy('name')->values()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'allowance_rules' => $site->allowanceRules->sortBy('effective_from')->values()->map(fn ($r) => $this->row($r))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $site = Site::findOrFail($id);
        $site->fill($data);
        // The leave balance counts stored days by their site's current code, so a used type's meaning is fixed; cleared days count as used too.
        if ($site->isDirty(['kind', 'leave_code']) && DB::table('attendance_days')->where('site_id', $id)->exists()) {
            throw ValidationException::withMessages(['leave_code' => __('errors.leave_type_in_use')]);
        }
        $this->assertLeaveCode($site);
        $site->save();

        return $this->show($id);
    }

    public function delete(string $id): void
    {
        Site::findOrFail($id)->delete();
    }

    /**
     * @param  array{effective_from: string, mode: string, value?: ?string}  $data
     * @return array<string, mixed>
     */
    public function addRule(string $id, array $data): array
    {
        $site = Site::findOrFail($id);
        if ($site->allowanceRules()->where('effective_from', $data['effective_from'])->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }
        $none = $data['mode'] === AllowanceMode::None->value;
        if (! $none && blank($data['value'] ?? null)) {
            throw ValidationException::withMessages(['value' => __('errors.allowance_value_required')]);
        }
        $rule = [...$data, 'value' => $none ? '0' : $data['value']];
        DB::transaction(function () use ($site, $rule): void {
            // M1: a site's rule reaches the days of every type worked there.
            if (PeriodGuard::lockedFrom($rule['effective_from'], ...EmployeeType::cases())) {
                throw ValidationException::withMessages(['effective_from' => __('errors.dated_change_period_locked')]);
            }
            $site->allowanceRules()->create($rule);
        });

        return $this->show($id);
    }

    /**
     * @param  list<string>  $managerIds
     * @return array<string, mixed>
     */
    public function setManagers(string $id, array $managerIds): array
    {
        DB::transaction(function () use ($id, $managerIds): void {
            $site = Site::lockForUpdate()->findOrFail($id);
            $old = $site->managers()->pluck('users.id')->sort()->values()->all();
            $site->managers()->sync($managerIds);
            $new = collect($managerIds)->unique()->sort()->values()->all();
            AuditLog::record(IdentityAuditEvents::SITE_MANAGERS_UPDATED, 'sites', $id, ['manager_ids' => $old], ['manager_ids' => $new]);
        });

        return $this->show($id);
    }

    /**
     * The email tells two namesakes apart; every role holding site.assign_manager also holds user.view (ManagerScopeTest).
     *
     * @return list<array{id: string, name: string, email: string}> the active accounts a site can have as its managers
     */
    public function managerCandidates(): array
    {
        return array_values(User::whereIn('id', $this->managerCandidateIds())->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email])->all());
    }

    /** @return list<string> */
    public function managerCandidateIds(): array
    {
        return array_values(array_diff($this->holders->userIds('site.view.own'), $this->holders->userIds('site.view.all')));
    }

    /**
     * The role a new site-manager account gets: the one live role holding site.view.own without site.view.all, the rule
     * managerCandidateIds() applies to accounts, so an account given it is a candidate.
     * ponytail: one site-manager role today; accept a role_id from the caller when a second exists.
     */
    public function managerRoleId(): string
    {
        $ids = array_values(array_diff($this->holders->roleIds('site.view.own'), $this->holders->roleIds('site.view.all')));
        if (count($ids) !== 1) {
            throw ValidationException::withMessages(['role' => __('errors.site_manager_role')]);
        }

        return $ids[0];
    }

    /**
     * Makes $siteIds the sites $managerId manages. Each added or removed site goes through setManagers, which keeps its other
     * managers and audits it; the changed sites are locked in id order first, so two calls cannot deadlock. A deleted site is left as is.
     *
     * @param  list<string>  $siteIds
     */
    public function setSitesOf(string $managerId, array $siteIds): void
    {
        DB::transaction(function () use ($managerId, $siteIds): void {
            $current = $this->idsManagedBy($managerId);
            $changed = array_values(array_unique([...array_diff($siteIds, $current), ...array_diff($current, $siteIds)]));
            foreach (Site::whereKey($changed)->orderBy('id')->lockForUpdate()->get() as $site) {
                $managers = array_values($site->managers()->pluck('users.id')->map(fn (mixed $id) => (string) $id)->all());
                $this->setManagers((string) $site->id, in_array((string) $site->id, $siteIds, true)
                    ? [...$managers, $managerId]
                    : array_values(array_diff($managers, [$managerId])));
            }
        });
    }

    /** @return list<string> the sites the user manages (P1: undated, so the current set on any date) */
    public function idsManagedBy(string $managerId): array
    {
        return array_values(DB::table('manager_sites')->where('manager_id', $managerId)->pluck('site_id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * The names of these sites, deleted ones included (a notification or a past day may still name one).
     *
     * @param  list<string>  $ids
     * @return array<array{id: string, name_ar: string, name_en: ?string}> by site id
     */
    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (Site::withTrashed()->whereKey(array_values(array_unique($ids)))->get(['id', 'name_ar', 'name_en']) as $site) {
            $names[(string) $site->id] = ['id' => (string) $site->id, 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en];
        }

        return $names;
    }

    /** @return Builder<Site> */
    private function visibleTo(?string $ownerId): Builder
    {
        $query = Site::query();
        if ($ownerId !== null) {
            $query->where(fn (Builder $q) => $q
                ->where('kind', SiteKind::Leave->value)
                ->orWhereHas('managers', fn (Builder $m) => $m->whereKey($ownerId)));
        }

        return $query;
    }

    private function assertLeaveCode(Site $site): void
    {
        $leave = $site->kind === SiteKind::Leave->value;
        if ($leave && blank($site->leave_code)) {
            throw ValidationException::withMessages(['leave_code' => __('errors.leave_code_required')]);
        }
        if (! $leave && filled($site->leave_code)) {
            throw ValidationException::withMessages(['leave_code' => __('errors.leave_code_not_allowed')]);
        }
        // sites_active_leave_code_unique is the guarantee; this turns the common case into a field error.
        if ($leave && $site->active !== false && Site::where(['kind' => SiteKind::Leave->value, 'leave_code' => $site->leave_code, 'active' => true])
            ->when($site->exists, fn (Builder $q) => $q->whereKeyNot($site->getKey()))->exists()) {
            throw ValidationException::withMessages(['leave_code' => __('errors.leave_code_taken')]);
        }
    }
}
