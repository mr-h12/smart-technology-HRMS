<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Residences, residence x site fares and per-employee fare overrides. */
final class FareService
{
    use Paginates;

    public function __construct(
        private readonly SiteService $sites,
        private readonly FareLookup $lookup,
    ) {}

    /**
     * @param  array{per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function listResidences(array $filters): array
    {
        return $this->page(Residence::query()->orderBy('name_ar'), $filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createResidence(array $data): array
    {
        return $this->row(Residence::create($data));
    }

    /** @return array<string, mixed> */
    public function showResidence(string $id): array
    {
        return $this->row(Residence::findOrFail($id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateResidence(string $id, array $data): array
    {
        $residence = Residence::findOrFail($id);
        $residence->update($data);

        return $this->row($residence);
    }

    public function deleteResidence(string $id): void
    {
        Residence::findOrFail($id)->delete();
    }

    /**
     * Rows carry `set_by` (the last writer; null = the starting values from Excel).
     *
     * @param  array{residence_id?: string, site_id?: string, date?: string, manager_id?: string, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function listFares(array $filters, ?string $ownerId = null): array
    {
        $query = Fare::query()->select('fares.*')
            ->selectRaw('fares.updated_by as set_by_id, (select u.name from users u where u.id = fares.updated_by) as set_by_name')
            // by name, as HR reads the list; the names of a deleted site or residence still sort its rows
            ->orderByRaw('(select s.name_ar from sites s where s.id = fares.site_id), (select r.name_ar from residences r where r.id = fares.residence_id), fares.effective_from');
        foreach (['residence_id', 'site_id'] as $col) {
            if (isset($filters[$col])) {
                $query->where($col, $filters[$col]);
            }
        }
        // Both narrow: an own-scope manager_id can only shrink the user's own sites.
        foreach ([$ownerId, $filters['manager_id'] ?? null] as $managerId) {
            if ($managerId !== null) {
                $query->whereIn('site_id', $this->sites->idsManagedBy($managerId));
            }
        }
        if (isset($filters['date'])) {
            // only the row in force on that date per (residence, site)
            $query->whereIn('id', fn ($q) => $q->from('fares')->selectRaw('distinct on (residence_id, site_id) id')
                ->where('effective_from', '<=', $filters['date'])->whereNull('deleted_at')
                ->orderBy('residence_id')->orderBy('site_id')->orderByDesc('effective_from'));
        }

        $page = $this->page($query, $filters['per_page'] ?? 20);
        $page['data'] = array_map(function (array $row): array {
            $setBy = $row['set_by_id'] === null ? null : ['id' => $row['set_by_id'], 'name' => $row['set_by_name']];
            unset($row['set_by_id'], $row['set_by_name']);

            return [...$row, 'set_by' => $setBy];
        }, $page['data']);

        return $page;
    }

    /**
     * The importer's strict add; the row reads as «من الإكسيل» (no writer).
     *
     * @param  array{residence_id: string, site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function addFare(array $data): array
    {
        $key = ['residence_id' => $data['residence_id'], 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];
        if (Fare::where($key)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }

        return $this->fromExcel(Fare::create($data));
    }

    /**
     * The importer's strict add; the row reads as «من الإكسيل» (no writer).
     *
     * @param  array{site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function addOverride(string $employeeId, array $data): array
    {
        $employee = Employee::findOrFail($employeeId);
        $key = ['employee_id' => $employee->getKey(), 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];
        if (EmployeeFareOverride::where($key)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }

        return $this->fromExcel(EmployeeFareOverride::create([...$data, 'employee_id' => $employee->getKey()]));
    }

    /** @return array<string, mixed> */
    private function fromExcel(Fare|EmployeeFareOverride $row): array
    {
        // creating() stamps the signed-in importer; a query-builder update skips the model events.
        $row->newQuery()->whereKey($row->getKey())->toBase()->update(['created_by' => null, 'updated_by' => null]);

        return $this->row($row);
    }

    /**
     * A site manager's team on a date (EmployeeReach, employed on the date) with the fare in force: workers at their site on the date (as reach reads it);
     * engineers by their overrides at the manager's sites, since they never take the residence x site default.
     * ponytail: unpaginated with per-row lookups (~3 queries a worker); a team is tens of rows, one joined query if one passes a few hundred
     *
     * @return array{sites: list<array{id: string, name: string}>, rows: list<array<string, mixed>>}
     */
    public function team(string $managerId, string $date): array
    {
        $siteIds = $this->sites->idsManagedBy($managerId);
        // Deleted sites and residences still name the rows that point at them; only live sites are offered.
        $all = Site::withTrashed()->whereKey($siteIds)->orderBy('name_ar')->get();
        $siteNames = $all->pluck('name_ar', 'id')->all();
        $sites = array_values($all->whereNull('deleted_at')->map(fn (Site $s) => ['id' => (string) $s->getKey(), 'name' => (string) $s->name_ar])->all());
        $employees = EmployeeReach::apply(Employee::query()->select('employees.*')
            ->selectRaw(Employee::TYPE_ON.' as type_on', [$date])
            ->selectRaw(Employee::SITE_ON.' as site_on', [$date, $date, $date]), $managerId, $date)
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $date))
            ->orderBy('code')->get();
        $residences = Residence::withTrashed()->whereKey($employees->pluck('residence_id')->filter()->unique()->values()->all())->pluck('name_ar', 'id')->all();

        $rows = $employees->map(function (Employee $e) use ($date, $siteIds, $sites, $siteNames, $residences): array {
            $type = (string) $e->getAttribute('type_on');
            $residenceId = $e->residence_id === null ? null : (string) $e->residence_id;
            $row = [
                'employee_id' => (string) $e->getKey(), 'code' => (int) $e->code, 'name' => (string) $e->name_ar, 'type' => $type,
                'residence' => $residenceId === null ? null : ['id' => $residenceId, 'name' => (string) $residences[$residenceId]],
                'site' => null, 'amount' => null, 'source' => 'none', 'default_amount' => null, 'overrides' => [],
            ];
            if ($type === EmployeeType::Engineer->value) {
                $inForce = EmployeeFareOverride::where('employee_id', $e->getKey())->whereIn('site_id', $siteIds)->where('effective_from', '<=', $date)
                    ->orderByDesc('effective_from')->get()->unique('site_id')->keyBy('site_id');
                foreach (array_column($sites, 'id') as $siteId) {
                    if (isset($inForce[$siteId])) {
                        $row['overrides'][] = ['site_id' => $siteId, 'amount' => (string) $inForce[$siteId]->amount];
                    }
                }

                return $row;
            }
            $siteId = (string) $e->getAttribute('site_on');
            $fare = $this->lookup->describe((string) $e->getKey(), $siteId, $date);

            return [
                ...$row,
                'site' => ['id' => $siteId, 'name' => (string) $siteNames[$siteId]],
                'amount' => in_array($fare['source'], ['override', 'default'], true) ? $fare['amount'] : null,
                'source' => $fare['source'],
                'default_amount' => $fare['default_amount'],
            ];
        })->all();

        return ['sites' => $sites, 'rows' => array_values($rows)];   // Collection::all() is not a list to PHPStan
    }

    /**
     * The API write: same (residence, site, effective_from) updates the amount. The importer keeps the strict addFare.
     *
     * @param  array{residence_id: string, site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function saveFare(array $data): array
    {
        $key = ['residence_id' => $data['residence_id'], 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];

        return DB::transaction(function () use ($key, $data): array {
            // A pair fare is the default of both worker types, so a lock of either refuses it.
            $this->assertUnlocked($data['effective_from'], EmployeeType::PermanentWorker, EmployeeType::DayLaborer);

            // updateOrCreate inserts in a savepoint and, losing a race on the unique key, updates the winner's row.
            return $this->row(Fare::updateOrCreate($key, ['amount' => $data['amount']]));
        });
    }

    /**
     * @param  array{site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function saveOverride(string $employeeId, array $data): array
    {
        $date = $data['effective_from'];
        $employee = Employee::query()->select('employees.*')->selectRaw(Employee::TYPE_ON.' as type_on', [$date])->findOrFail($employeeId);
        $type = EmployeeType::tryFrom((string) $employee->getAttribute('type_on'));
        $key = ['employee_id' => $employeeId, 'site_id' => $data['site_id'], 'effective_from' => $date];

        return DB::transaction(function () use ($date, $type, $key, $data): array {
            // With no type on the date, the override could govern any type's period.
            $this->assertUnlocked($date, ...($type === null ? EmployeeType::cases() : [$type]));

            return $this->row(EmployeeFareOverride::updateOrCreate($key, ['amount' => $data['amount']]));
        });
    }

    /**
     * FareLookup::describe, refused (422 site_id) for an own-scope user outside their sites.
     *
     * @return array{amount: string, source: 'override'|'default'|'none'|'no_residence', default_amount: ?string}
     */
    public function describe(string $employeeId, string $siteId, string $date, ?string $ownerId): array
    {
        $this->assertOwnSite($ownerId, $siteId);

        return $this->lookup->describe($employeeId, $siteId, $date);
    }

    private function assertOwnSite(?string $ownerId, string $siteId): void
    {
        if ($ownerId !== null && ! in_array($siteId, $this->sites->idsManagedBy($ownerId), true)) {
            throw ValidationException::withMessages(['site_id' => __('errors.fare_site_not_yours')]);
        }
    }

    /** With no later row, a fare governs every period after its date, so a lock ending on or after it refuses the write. */
    private function assertUnlocked(string $date, EmployeeType ...$types): void
    {
        if (PeriodGuard::lockedFrom($date, ...$types)) {
            throw ValidationException::withMessages(['effective_from' => __('errors.fare_period_locked')]);
        }
    }
}
