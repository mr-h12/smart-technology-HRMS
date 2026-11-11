<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\Money;
use App\Modules\Payroll\Domain\Rates;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\AllowanceRules;
use App\Modules\Sites\Application\FareLookup;
use App\Modules\Sites\Domain\Allowance;
use App\Modules\Sites\Domain\AllowanceMode;
use App\Support\Money\Decimal;

/**
 * The inputs assembler (design 2026-11-09, "Shape"): each employee's period days with every dated record resolved for the day, as
 * the attendance sheet shows them (EffectiveDays), and the settings as Rates. It reads other modules only through their read services.
 */
final class PayrollInputs
{
    public function __construct(
        private readonly EffectiveDays $effectiveDays,
        private readonly FareLookup $fares,
        private readonly AllowanceRules $allowances,
        private readonly BusinessRules $rules,
    ) {}

    /** The settings an engine needs, with the lateness multiplier of the sheet's type (§0a); the grace is every type's (§0a). */
    public function rates(EmployeeType $type): Rates
    {
        $s = $this->rules->all();

        return new Rates(
            Decimal::of($s['month_days'], 'month_days'),
            Decimal::of($s['day_hours'], 'day_hours'),
            Decimal::of($s['overtime_multiplier'], 'overtime_multiplier'),
            (int) $s['office_grace_minutes'],
            Decimal::of($s['lateness_multiplier_'.$type->value], 'lateness_multiplier'),
            Decimal::of($s['sick_leave_pay_percent'], 'sick_leave_pay_percent'),
        );
    }

    /**
     * Per employee: one DayInput per calendar day of the period, in date order from index 0; the insured basic of the pay row in force on
     * their last day of the group's type in the period; and `no_fare` when a day that pays a fare found none (the engines cannot tell
     * "no fare" from 0).
     *
     * @param  list<array<string, mixed>>  $employees  EmployeeRoster rows
     * @return array<string, array{days: list<DayInput>, insured_basic: numeric-string|null, flags: list<string>}> by employee id
     */
    public function forPeriod(Period $p, array $employees): array
    {
        $s = $this->rules->all();
        $effective = $this->effectiveDays->forPeriod($p, $employees);
        $rules = [];
        if ($p->group === EmployeeType::DayLaborer) {
            $siteIds = [];
            foreach ($effective as $days) {
                foreach ($days as $day) {
                    $siteIds[(string) $day['site_id']] = true;
                }
            }
            unset($siteIds['']);
            $rules = $this->allowances->inForce(array_map('strval', array_keys($siteIds)), $p->start, $p->end);
        }
        $fares = $this->fares->inForce(array_map(fn (array $e) => (string) $e['id'], $employees), $p->start, $p->end);

        $inputs = [];
        foreach ($employees as $e) {
            $id = (string) $e['id'];
            $input = ['days' => [], 'insured_basic' => null, 'flags' => []];
            foreach ($effective[$id] as $date => $day) {
                $state = self::state($p->group, $day);
                $pay = $state === 'not_employed' ? null : EffectiveDated::at($e['pays'], (string) $date);
                if ($state !== 'not_employed') {
                    $input['insured_basic'] = self::amount($pay['insured_basic'] ?? null);
                }
                $wage = self::wage($p->group, $pay);
                [$fare, $fareFound] = self::fare($fares, $p->group, $id, $state, $day);
                if (! $fareFound) {
                    $input['flags'] = ['no_fare'];
                }
                [$scheduledIn, $scheduledOut] = self::schedule($p->group, $e, $day, $s);
                $input['days'][] = new DayInput(
                    date: (string) $date,
                    state: $state,
                    leaveCode: $day['leave_code'],
                    siteId: $day['site_id'],
                    in: $day['in_time'],
                    out: $day['out_time'],
                    scheduledIn: $scheduledIn,
                    scheduledOut: $scheduledOut,
                    lateMinutes: (int) ($day['late_minutes'] ?? 0),
                    otMinutes: (int) ($day['ot_minutes'] ?? 0),
                    outsideMinutes: (int) ($day['outside_minutes'] ?? 0),
                    outsideAmount: self::amount($day['outside_amount']),
                    transport: match ($day['transport']) {
                        'half' => 'half',
                        'none' => 'none',
                        default => 'full',   // a permanent worker's stored portion; engineers' and day laborers' days store none (DayRules::FIELDS)
                    },
                    advance: self::amount($day['advance_amount']),
                    wage: $wage,
                    allowance: $p->group === EmployeeType::DayLaborer ? self::allowance($rules, $state, $day, $wage) : '0',
                    fare: $fare,
                );
            }
            $inputs[$id] = $input;
        }

        return $inputs;
    }

    /**
     * The engine's day state from the attendance day: a stored or derived leave day-type is leave; a stored field day with an arrival
     * and no departure is open (office days never are, calculations §2b); any other stored day is worked.
     *
     * @param  array<string, mixed>  $day  an EffectiveDays day
     * @return 'work'|'leave'|'missing'|'not_employed'|'open'|'future'
     */
    private static function state(EmployeeType $group, array $day): string
    {
        return match ($day['state']) {
            'stored' => match (true) {
                $day['leave_code'] !== null => 'leave',
                $group !== EmployeeType::Office && $day['in_time'] !== null && $day['out_time'] === null => 'open',
                default => 'work',
            },
            'derived' => 'leave',
            'missing' => 'missing',
            'future' => 'future',
            default => 'not_employed',   // the one state left (EffectiveDays::forPeriod)
        };
    }

    /**
     * The day's wage from the pay row in force, reading only the group's fields (a legacy row may hold another type's): the daily wage
     * for a day laborer, salary + raise for monthly staff. Null without a pay row or that field (the engines flag `no_wage`).
     *
     * @param  array<string, mixed>|null  $pay
     * @return numeric-string|null
     */
    private static function wage(EmployeeType $group, ?array $pay): ?string
    {
        if ($group === EmployeeType::DayLaborer) {
            return self::amount($pay['daily_wage'] ?? null);
        }
        $salary = self::amount($pay['salary'] ?? null);

        return $salary === null ? null : Money::add($salary, self::amount($pay['raise'] ?? null) ?? '0');
    }

    /**
     * The fare of a worked (or open) day at its site, and whether one was found. Engineers: the per-worker override in force only, 0
     * without one, never the residence × site fare (R2; calculations §2). Day laborers and permanent workers on a day that pays
     * transport: the override, else the residence × site fare (§4); none found is reported. Other days need no fare. The day's type is
     * the group's on a worked day.
     *
     * @param  array{residences: array<string, ?string>, overrides: array<string, array<string, list<array{effective_from: string, amount: string}>>>, fares: array<string, array<string, list<array{effective_from: string, amount: string}>>>}  $fares  FareLookup::inForce for the period
     * @param  array<string, mixed>  $day
     * @return array{numeric-string, bool}
     */
    private static function fare(array $fares, EmployeeType $group, string $employeeId, string $state, array $day): array
    {
        $paysFare = $group === EmployeeType::Engineer || $group === EmployeeType::DayLaborer
            || ($group === EmployeeType::PermanentWorker && $day['transport'] !== 'none');
        if (($state !== 'work' && $state !== 'open') || ! $paysFare) {
            return ['0', true];
        }
        $found = $day['site_id'] === null ? null : FareLookup::forDay($fares, $employeeId, $group, (string) $day['site_id'], (string) $day['date']);
        if ($group === EmployeeType::Engineer) {
            return [$found !== null && $found['source'] === 'override' ? Decimal::of($found['amount'], 'fare') : '0', true];
        }
        $known = $found !== null && in_array($found['source'], ['override', 'default'], true);

        return [$known ? Decimal::of($found['amount'], 'fare') : '0', $known];
    }

    /**
     * A day laborer's site allowance on a worked (or open) day: the site's rule in force that day applied to the day's wage (§4).
     *
     * @param  array<string, list<array{effective_from: string, mode: AllowanceMode, value: string}>>  $rules
     * @param  array<string, mixed>  $day
     * @param  numeric-string|null  $wage
     * @return numeric-string
     */
    private static function allowance(array $rules, string $state, array $day, ?string $wage): string
    {
        if (($state !== 'work' && $state !== 'open') || $day['site_id'] === null) {
            return '0';
        }
        /** @var array{effective_from: string, mode: AllowanceMode, value: string}|null $rule */
        $rule = EffectiveDated::at($rules[$day['site_id']] ?? [], (string) $day['date']);

        return $rule === null ? '0' : Decimal::of(Allowance::for($rule['mode']->value, Decimal::of($rule['value'], 'allowance'), $wage ?? '0'), 'allowance');
    }

    /**
     * The day's scheduled in and out, so the engines can read lateness and early departure: office the employee's shift, else the office
     * shift; permanent workers the day's stored times; engineers and day laborers the employee's shift, else the field shift (§2, §13).
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $day
     * @param  array<string, string>  $s  the settings
     * @return array{?string, ?string}
     */
    private static function schedule(EmployeeType $group, array $employee, array $day, array $s): array
    {
        if ($group === EmployeeType::Office) {
            $shift = Employment::shiftOn($employee, (string) $day['date']);

            return [$shift['start'] ?? $s['office_shift_start'], $shift['end'] ?? $s['office_shift_end']];
        }
        $shift = EffectiveDays::fieldShift($employee, (string) $day['date'], $s);

        return $group === EmployeeType::PermanentWorker
            ? [$day['scheduled_in'] ?? $shift['start'], $day['scheduled_out'] ?? $shift['end']]   // as the attendance hints read them
            : [$shift['start'], $shift['end']];
    }

    /** @return numeric-string|null */
    private static function amount(mixed $value): ?string
    {
        return $value === null ? null : Decimal::of((string) $value, 'amount');
    }
}
