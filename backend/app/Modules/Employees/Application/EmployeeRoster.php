<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeePayChange;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model of live employees with their dated types and shifts, so other modules never query Employees' tables.
 * A row is {id: string, code: int, name_ar: string, name_en: ?string, department: ?string, hire_date: ?string, termination_date: ?string, archived: bool,
 * types: list<{type, effective_from}>, shifts: list<{effective_from, shift_start: ?HH:MM, shift_end: ?HH:MM}>,
 * pays: list<{effective_from, salary: ?string, raise: string, insured_basic: ?string, daily_wage: ?string}> (as stored: a row may hold another type's fields; callers pick theirs),
 * sites: list<{site_id, effective_from}>, crew: list<{date, site_id: ?string}>}.
 * Every method returning a set of employees leaves archived ones out (owner, 2026-11-11) unless the caller asks with withArchived();
 * the by-id reads (find, findMany, names) and hiredBetween are unchanged.
 */
final class EmployeeRoster
{
    use Paginates;

    private const RELATIONS = ['typeChanges', 'payChanges', 'siteAssignments', 'crewChanges'];

    private bool $includeArchived = false;

    /**
     * Some type row of $type overlaps [from, to] and the employment window; greatest/least skip a null hire date, termination date or next change.
     * `- 1` turns the next change's effective_from into this row's last day.
     */
    private const EMPLOYED_AS = 'exists (select 1 from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.type = ?
        and greatest(t.effective_from, employees.hire_date, ?::date) <= least(
            (select min(n.effective_from) from employee_type_changes n where n.employee_id = t.employee_id and n.deleted_at is null and n.effective_from > t.effective_from) - 1,
            employees.termination_date, ?::date))';

    /** The type in force on typeInYear's read date D; the bound `?` is min(Dec 31 of the year, today). greatest/least skip a null hire or termination date. */
    private const TYPE_IN_YEAR = '(select t.type from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null
        and t.effective_from <= greatest(employees.hire_date, least(?::date, employees.termination_date)) order by t.effective_from desc limit 1)';

    /**
     * The employees employed as any of $types within [from, to]. $ids, when set, narrows to those employees (an empty list gives none);
     * $search, when not blank, to either name containing it or the exact code (Employee::matching).
     *
     * @param  non-empty-list<EmployeeType>  $types
     * @param  array<string>|null  $ids
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function ofType(array $types, string $from, string $to, int $perPage, int $page, ?string $managedBy = null, ?array $ids = null, ?string $search = null): array
    {
        return $this->page($this->employedAs($types, $from, $to, $managedBy)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', (array) $ids))
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->matching((string) $search)), $perPage, $page);
    }

    /**
     * The employees of $year (hired by Dec 31 or with no hire date, not terminated before Jan 1) whose typeInYear() is one of $types, by code.
     * $search matches either name, or the exact code when it is a number. $managedBy narrows to that user's reach today.
     *
     * @param  non-empty-list<EmployeeType>  $types
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function ofTypesInYear(array $types, int $year, ?string $search, int $perPage, int $page, ?string $managedBy = null): array
    {
        $today = now('Africa/Cairo')->toDateString();
        $values = array_map(fn (EmployeeType $t) => $t->value, $types);
        $query = $this->employees()->with(self::RELATIONS)
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', "{$year}-12-31"))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', "{$year}-01-01"))
            ->whereRaw(self::TYPE_IN_YEAR.' in ('.implode(',', array_fill(0, count($values), '?')).')', [min("{$year}-12-31", $today), ...$values])
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->matching((string) $search))
            ->when($managedBy !== null, fn (Builder $q) => EmployeeReach::apply($q, (string) $managedBy, $today))
            ->orderBy('code');

        return $this->page($query, $perPage, $page);
    }

    /** A copy whose set-returning methods also return archived employees: the lock, the import and HR's grid switch. */
    public function withArchived(bool $include = true): self
    {
        $copy = clone $this;
        $copy->includeArchived = $include;

        return $copy;
    }

    /**
     * The employee's type for $year, read on D = max(hire date, min(today, Dec 31 of $year, termination date)), a null date left out
     * (calculations §7). TYPE_IN_YEAR is the same read in SQL. Null when no type is in force on D.
     * ponytail: a type history that starts after the hire date or after the termination date still reads no type on D (bad data);
     * ofTypesInYear then leaves the employee out while their Leave tab keeps a balance. Fix the data if it appears.
     *
     * @param  array<string, mixed>  $employee  a roster row
     */
    public static function typeInYear(array $employee, int $year): ?string
    {
        $d = min(array_filter([now('Africa/Cairo')->toDateString(), "{$year}-12-31", $employee['termination_date']]));
        $d = max($d, $employee['hire_date'] ?? $d);

        return EffectiveDated::at($employee['types'], $d)['type'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function allOfType(EmployeeType $type, string $from, string $to, ?string $managedBy = null): array
    {
        return $this->rows($this->employedAs([$type], $from, $to, $managedBy));
    }

    /**
     * @return list<array<string, mixed>> the workers employed on $date whose site on $date (that day's crew change, else the home site) is $siteId
     *                                    and, when $managedBy is set, whom that user reaches then (never their own record), by code
     */
    public function onSite(string $siteId, string $date, ?string $managedBy): array
    {
        return $this->rows($this->employees()->with(self::RELATIONS)
            ->where(fn (Builder $q) => $q->whereRaw(self::EMPLOYED_AS, [EmployeeType::PermanentWorker->value, $date, $date])
                ->orWhereRaw(self::EMPLOYED_AS, [EmployeeType::DayLaborer->value, $date, $date]))
            ->whereRaw(Employee::SITE_ON.' = ?', [$date, $date, $date, $siteId])
            ->when($managedBy !== null, fn (Builder $q) => EmployeeReach::apply($q, (string) $managedBy, $date))
            ->orderBy('code'));
    }

    /**
     * The site workbook's name list (calculations §14 W4), with no manager reach: it is HR's file. own = the workers and office staff whose
     * site on $from is $siteId and the engineers assigned on $from to any of $managerIds; others = every other day laborer, permanent worker
     * and engineer employed on some day of [$from, $to]. Each group by name_ar in code-point order (as the C.UTF-8 database sorts), then code.
     *
     * @param  list<string>  $managerIds
     * @return array{own: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public function siteWorkbookPeople(string $siteId, array $managerIds, string $from, string $to): array
    {
        $own = array_column([
            ...$this->onSite($siteId, $from, null),
            ...$this->rows($this->employees()->with(self::RELATIONS)->whereKey($this->officeIdsAt($siteId, $from))),
            ...array_merge([], ...array_map(fn (string $m) => $this->engineersOf($m, $from), $managerIds)),
        ], null, 'id');
        $field = [EmployeeType::DayLaborer, EmployeeType::PermanentWorker, EmployeeType::Engineer];
        $others = array_filter($this->rows($this->employedAs($field, $from, $to, null)), fn (array $e) => ! isset($own[$e['id']]));
        $byName = fn (array $a, array $b) => strcmp($a['name_ar'], $b['name_ar']) ?: $a['code'] <=> $b['code'];
        usort($own, $byName);
        usort($others, $byName);

        return ['own' => $own, 'others' => $others];
    }

    /** @return list<string> the office staff whose site on $date is $siteId; that site only lists them there (owner, 2026-11-08) */
    public function officeIdsAt(string $siteId, string $date): array
    {
        return array_values($this->employees()
            ->whereRaw(Employee::TYPE_ON.' = ?', [$date, EmployeeType::Office->value])
            ->whereRaw(Employee::SITE_ON.' = ?', [$date, $date, $date, $siteId])
            ->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /** @return list<array<string, mixed>> the engineers employed on $date and assigned to $managerId on $date, by code */
    public function engineersOf(string $managerId, string $date): array
    {
        return $this->rows(EmployeeReach::apply($this->employedAs([EmployeeType::Engineer], $date, $date, null), $managerId, $date));
    }

    /** @return Builder<Employee> the ids of the archived employees, as a whereIn subquery */
    public function archivedIds(): Builder
    {
        return Employee::query()->select('employees.id')->archived();
    }

    /** @return array<string, mixed>|null with $withTrashed, a deleted employee too */
    public function find(string $id, bool $withTrashed = false): ?array
    {
        $e = Employee::with(self::RELATIONS)->when($withTrashed, fn (Builder $q) => $q->withTrashed())->find($id);

        return $e === null ? null : $this->row($e);
    }

    /** The id of the employee holding $nationalId, a deleted one too: the column is unique over every row (create_employees_tables). */
    public function idWithNationalId(string $nationalId): ?string
    {
        $id = Employee::withTrashed()->where('national_id', $nationalId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @param  list<string>  $ids
     * @return array<array-key, array<string, mixed>> keyed by id (a UUID, so never cast to int); unknown ids are left out
     */
    public function findMany(array $ids): array
    {
        $rows = [];
        foreach (Employee::with(self::RELATIONS)->whereKey($ids)->get() as $e) {
            $rows[(string) $e->id] = $this->row($e);
        }

        return $rows;
    }

    /**
     * Who a stored id points to, for labels.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, code: int, name_ar: string, name_en: ?string}> keyed by id; unknown and deleted ids are left out
     */
    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach (Employee::whereKey($ids)->get(['id', 'code', 'name_ar', 'name_en']) as $e) {
            $id = (string) $e->getKey();
            $rows[$id] = ['id' => $id, 'code' => (int) $e->code, 'name_ar' => (string) $e->name_ar, 'name_en' => $e->name_en];
        }

        return $rows;
    }

    /**
     * The live employees hired from $from to $to (inclusive), by code.
     *
     * @return list<array<string, mixed>>
     */
    public function hiredBetween(string $from, string $to): array
    {
        return $this->rows(Employee::with(self::RELATIONS)->whereBetween('hire_date', [$from, $to])->orderBy('code'));
    }

    /**
     * @param  non-empty-list<EmployeeType>  $types  employed as any of them
     * @return Builder<Employee> narrowed, when $managedBy is set, to the employees in that user's reach on min($to, today) (P4)
     */
    private function employedAs(array $types, string $from, string $to, ?string $managedBy): Builder
    {
        return $this->employees()->with(self::RELATIONS)
            ->where(function (Builder $q) use ($types, $from, $to) {
                foreach ($types as $t) {
                    $q->orWhereRaw(self::EMPLOYED_AS, [$t->value, $from, $to]);
                }
            })
            ->when($managedBy !== null, fn (Builder $q) => EmployeeReach::apply($q, (string) $managedBy, min($to, now('Africa/Cairo')->toDateString())))
            ->orderBy('code');
    }

    /** @return Builder<Employee> where every set-returning query starts, so none can forget to leave archived employees out unless withArchived() */
    private function employees(): Builder
    {
        return Employee::query()->when(! $this->includeArchived, fn (Builder $q) => $q->archived(false));
    }

    /**
     * @param  Builder<Employee>  $query
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query): array
    {
        return array_values($query->get()->map(fn (Employee $e) => $this->row($e))->all());
    }

    /** @return array<string, mixed> */
    private function row(Model $e): array
    {
        /** @var Employee $e */
        $hhmm = fn (?string $t) => $t === null ? null : substr($t, 0, 5);

        return [
            'id' => (string) $e->id,
            'code' => (int) $e->code,
            'name_ar' => (string) $e->name_ar,
            'name_en' => $e->name_en,
            'department' => $e->department,
            'hire_date' => $e->hire_date,
            'termination_date' => $e->termination_date,
            'archived' => Employee::isArchived($e->termination_date),
            'types' => array_values($e->typeChanges->sortBy('effective_from')->map(fn (EmployeeTypeChange $t) => [
                'type' => (string) $t->type, 'effective_from' => (string) $t->effective_from,
            ])->all()),
            'shifts' => array_values($e->payChanges->sortBy('effective_from')->map(fn (EmployeePayChange $p) => [
                'effective_from' => (string) $p->effective_from, 'shift_start' => $hhmm($p->shift_start), 'shift_end' => $hhmm($p->shift_end),
            ])->all()),
            'pays' => array_values($e->payChanges->sortBy('effective_from')->map(fn (EmployeePayChange $p) => [
                'effective_from' => (string) $p->effective_from, 'salary' => $p->salary, 'raise' => (string) $p->raise,
                'insured_basic' => $p->insured_basic, 'daily_wage' => $p->daily_wage,
            ])->all()),
            'sites' => array_values($e->siteAssignments->sortBy('effective_from')->map(fn (EmployeeSiteAssignment $s) => [
                'site_id' => (string) $s->site_id, 'effective_from' => (string) $s->effective_from,
            ])->all()),
            'crew' => array_values($e->crewChanges->map(fn (DayCrewChange $c) => [
                'date' => (string) $c->date, 'site_id' => $c->site_id === null ? null : (string) $c->site_id,
            ])->all()),
        ];
    }
}
