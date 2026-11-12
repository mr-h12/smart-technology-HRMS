<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Domain\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare as FareRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The daily fare an employee gets for a site on a date: their override, else (workers only) residence x site, else none. */
final class FareLookup
{
    private const WORKERS = [EmployeeType::PermanentWorker->value, EmployeeType::DayLaborer->value];

    public function for(string $employeeId, string $siteId, string $date): string
    {
        return $this->describe($employeeId, $siteId, $date)['amount'];
    }

    /** @return array{amount: string, source: 'override'|'default'|'none'|'no_residence', default_amount: ?string} */
    public function describe(string $employeeId, string $siteId, string $date): array
    {
        $employee = Employee::query()->select('employees.*')->selectRaw(Employee::TYPE_ON.' as type_on', [$date])->findOrFail($employeeId);
        $isWorker = in_array($employee->getAttribute('type_on'), self::WORKERS, true);

        $override = EmployeeFareOverride::where('employee_id', $employeeId)->where('site_id', $siteId)
            ->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first()?->amount;
        $default = ! $isWorker || $employee->residence_id === null ? null : FareRow::where('residence_id', $employee->residence_id)
            ->where('site_id', $siteId)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first()?->amount;

        return self::resolved($override, $default, $isWorker && $employee->residence_id === null);
    }

    /**
     * Every fare a period of these employees can need, in three queries whatever the headcount (Payroll): their residences, their
     * overrides and their residences' fares, by site and date. forDay() resolves a day from it.
     *
     * @param  list<string>  $employeeIds
     * @return array{residences: array<string, ?string>, overrides: array<string, array<string, list<array{effective_from: string, amount: string}>>>, fares: array<string, array<string, list<array{effective_from: string, amount: string}>>>}
     */
    public function inForce(array $employeeIds, string $from, string $to): array
    {
        $residences = [];
        foreach (Employee::whereKey($employeeIds)->get(['id', 'residence_id']) as $e) {
            $residences[(string) $e->id] = $e->residence_id === null ? null : (string) $e->residence_id;
        }

        return [
            'residences' => $residences,
            'overrides' => self::dated(EmployeeFareOverride::whereIn('employee_id', $employeeIds), 'employee_fare_overrides', 'employee_id', $from, $to),
            'fares' => self::dated(FareRow::whereIn('residence_id', array_values(array_unique(array_filter($residences)))), 'fares', 'residence_id', $from, $to),
        ];
    }

    /**
     * describe() for one day of inForce()'s period, with no query. $type is the employee's type that day.
     *
     * @param  array{residences: array<string, ?string>, overrides: array<string, array<string, list<array{effective_from: string, amount: string}>>>, fares: array<string, array<string, list<array{effective_from: string, amount: string}>>>}  $inForce
     * @return array{amount: string, source: 'override'|'default'|'none'|'no_residence', default_amount: ?string}
     */
    public static function forDay(array $inForce, string $employeeId, ?EmployeeType $type, string $siteId, string $date): array
    {
        $isWorker = in_array($type?->value, self::WORKERS, true);
        $residence = $inForce['residences'][$employeeId] ?? null;
        $override = EffectiveDated::at($inForce['overrides'][$employeeId][$siteId] ?? [], $date)['amount'] ?? null;
        $default = ! $isWorker || $residence === null ? null : EffectiveDated::at($inForce['fares'][$residence][$siteId] ?? [], $date)['amount'] ?? null;

        return self::resolved($override, $default, $isWorker && $residence === null);
    }

    /**
     * @param  numeric-string|null  $override
     * @param  numeric-string|null  $default
     * @return array{amount: string, source: 'override'|'default'|'none'|'no_residence', default_amount: ?string}
     */
    private static function resolved(?string $override, ?string $default, bool $noResidence): array
    {
        return [
            'amount' => Fare::resolve($override, $default),
            'source' => match (true) {
                $override !== null => 'override',
                $default !== null => 'default',
                $noResidence => 'no_residence',
                default => 'none',
            },
            'default_amount' => $default,
        ];
    }

    /**
     * $table's rows of $query in force on `from` per (owner, site), plus every later one starting on or before `to`, as
     * AllowanceRules::inForce reads allowance rules.
     *
     * @param  Builder<covariant Model>  $query  dated rows (effective_from, site_id, amount) narrowed to their owners
     * @param  literal-string  $table
     * @param  literal-string  $owner
     * @return array<string, array<string, list<array{effective_from: string, amount: string}>>> owner id → site id → rows by date
     */
    private static function dated(Builder $query, string $table, string $owner, string $from, string $to): array
    {
        $inPeriod = "(effective_from >= ? or effective_from = (select max(r.effective_from) from {$table} r
            where r.{$owner} = {$table}.{$owner} and r.site_id = {$table}.site_id and r.deleted_at is null and r.effective_from <= ?))";
        $rows = [];
        foreach ($query->where('effective_from', '<=', $to)->whereRaw($inPeriod, [$from, $from])->orderBy('effective_from')->get() as $r) {
            $rows[(string) $r->getAttribute($owner)][(string) $r->getAttribute('site_id')][] = [
                'effective_from' => (string) $r->getAttribute('effective_from'), 'amount' => (string) $r->getAttribute('amount'),
            ];
        }

        return $rows;
    }
}
