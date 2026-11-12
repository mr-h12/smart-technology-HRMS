<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string|null $gender null only for a day laborer a site manager added, until HR completes the record */
class Employee extends Model
{
    use Audited;
    use HasStandardColumns;

    /** The employee's type in force on the bound date `?`. */
    public const TYPE_ON = '(select t.type from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.effective_from <= ?::date order by t.effective_from desc limit 1)';

    /**
     * The worker's site on the bound date (three `?`, all that date): that day's crew change when there is one (its site, or null when
     * taken off every crew), else the home site in force. EmployeeRoster::row's `crew` and `sites` are the same read in PHP.
     */
    public const SITE_ON = '(case when exists (select 1 from day_crew_changes c where c.employee_id = employees.id and c.deleted_at is null and c.date = ?::date)
        then (select c.site_id from day_crew_changes c where c.employee_id = employees.id and c.deleted_at is null and c.date = ?::date)
        else (select s.site_id from employee_site_assignments s where s.employee_id = employees.id and s.deleted_at is null and s.effective_from <= ?::date order by s.effective_from desc limit 1) end)';

    protected $guarded = [];

    /**
     * Either name contains $search, or the code equals it when it is a number; $nationalId adds the national id (HR only).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $search, bool $nationalId = false): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        // Every arm must be indexable or Postgres scans the table: trigram indexes for the text, the code's unique index for a number.
        $query->where(fn (Builder $q) => $q
            ->where('name_ar', 'ilike', $like)
            ->orWhere('name_en', 'ilike', $like)
            ->when($nationalId, fn (Builder $q) => $q->orWhere('national_id', 'like', $like))
            ->when(preg_match('/^(0|[1-9]\d{0,8})$/D', $search) === 1, fn (Builder $q) => $q->orWhere('code', (int) $search)));
    }

    /**
     * Archived = the termination date is before today in Cairo; the termination day itself is still worked (CONTEXT.md, Archive).
     * The one rule every caller uses: this for PHP, the `archived` scope for SQL.
     */
    public static function isArchived(?string $terminationDate): bool
    {
        return $terminationDate !== null && $terminationDate < now('Africa/Cairo')->toDateString();
    }

    /**
     * archived() keeps the archived employees, archived(false) the others. Qualified: EmployeeReach applies it to other modules' queries.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function archived(Builder $query, bool $archived = true): void
    {
        $column = $query->qualifyColumn('termination_date');
        $today = now('Africa/Cairo')->toDateString();
        $query->where(fn (Builder $q) => $archived
            ? $q->where($column, '<', $today)
            : $q->whereNull($column)->orWhere($column, '>=', $today));
    }

    /** @return HasMany<EmployeeTypeChange, $this> */
    public function typeChanges(): HasMany
    {
        return $this->hasMany(EmployeeTypeChange::class);
    }

    /** @return HasMany<EmployeePayChange, $this> */
    public function payChanges(): HasMany
    {
        return $this->hasMany(EmployeePayChange::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<EmployeeManagerAssignment, $this> */
    public function managerAssignments(): HasMany
    {
        return $this->hasMany(EmployeeManagerAssignment::class);
    }

    /** @return HasMany<EmployeeSiteAssignment, $this> */
    public function siteAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }

    /** @return HasMany<DayCrewChange, $this> */
    public function crewChanges(): HasMany
    {
        return $this->hasMany(DayCrewChange::class);
    }
}
