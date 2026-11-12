<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Modules\Attendance\Domain\DayRules;

/** An office employee's line for one calendar month: calculations §1, lateness §0a, leave pay §7, §10. */
final class OfficeEngine
{
    /**
     * @param  list<DayInput>  $days  the month's days in date order
     * @param  array{lateness_exemption_minutes?: int|null, ot_minutes?: int|null, travel_days?: int|null, tower_days?: int|null, bonus?: numeric-string|null, negligence?: numeric-string|null, advances?: numeric-string|null, insurance_employee?: numeric-string|null, insurance_company?: numeric-string|null, income_tax?: numeric-string|null, insured_basic?: numeric-string|null}  $entry  HR's inputs for the period and the pay row's insured basic; blank = 0
     */
    public static function compute(array $days, Rates $r, array $entry): Line
    {
        $minuteDivisor = Money::mul(Money::mul($r->monthDays, $r->dayHours), '60');   // the salary's minute divisor, 14,400 = 30 × 8 × 60
        $lateMinutes = 0;
        $dayLateness = '0';
        $dayLines = [];
        foreach ($days as $d) {
            [$late, $lateness, $dayLine] = self::day($d, $r, $minuteDivisor);
            $lateMinutes += $late;
            $dayLateness = Money::add($dayLateness, $lateness);
            $dayLines[] = $dayLine;
        }

        // §1: net late minutes = Σ daily − HR's exemption, never below 0. Each late minute costs its own day's salary (§0a),
        // so the exemption waives the same share of every one.
        $netLateMinutes = max(0, $lateMinutes - ($entry['lateness_exemption_minutes'] ?? 0));
        $lateness = $lateMinutes === 0 ? '0' : Money::div(Money::mul($dayLateness, (string) $netLateMinutes), (string) $lateMinutes);

        ['salary' => $salary, 'not_employed' => $notEmployed] = MonthlyBase::salary($days, $r);
        ['absence' => $absence, 'unpaid' => $unpaid, 'sick_cut' => $sickCut] = MonthlyBase::leave($days, $r);
        // HR's period totals carry no date, so they are paid at the period's salary (each day's salary over the month).
        $overtime = Money::div(Money::mul(Money::mul((string) ($entry['ot_minutes'] ?? 0), $salary), $r->overtime), $minuteDivisor);
        $travel = Money::div(Money::mul((string) ($entry['travel_days'] ?? 0), $salary), $r->monthDays);                       // a day rate per travel day
        $tower = Money::div(Money::mul((string) ($entry['tower_days'] ?? 0), $salary), Money::mul($r->monthDays, '4'));   // a quarter day rate per tower day
        $insuredBasic = $entry['insured_basic'] ?? '0';

        // §1: net = salary + travel + tower + overtime + bonus − insurance − tax − advance − negligence − lateness − absence, less
        // the not-employed days and leave pay (§7, §10) (Line::net).
        return Line::computed([
            Component::Salary->value => $salary,
            Component::Travel->value => $travel,
            Component::Tower->value => $tower,
            Component::Overtime->value => $overtime,
            Component::Bonus->value => $entry['bonus'] ?? '0',
            Component::Lateness->value => $lateness,
            Component::Absence->value => $absence,
            Component::UnpaidLeave->value => $unpaid,
            Component::SickCut->value => $sickCut,
            Component::NotEmployed->value => $notEmployed,
            Component::Negligence->value => $entry['negligence'] ?? '0',
            Component::InsuranceEmployee->value => $entry['insurance_employee'] ?? '0',
            Component::IncomeTax->value => $entry['income_tax'] ?? '0',
            Component::Advances->value => $entry['advances'] ?? '0',
            Component::InsuranceCompany->value => $entry['insurance_company'] ?? '0',   // a company cost (§9), not in the net
            Component::InsuredBasic->value => $insuredBasic,
            Component::Incentives->value => Money::sub($salary, $insuredBasic),         // the payslip's split of the salary (§1)
        ], $dayLines);
    }

    /**
     * A day's chargeable late minutes, their cost at the day's salary (§0a) and its day line. Only a present day is late:
     * its in time after its shift start (the employee's own or the office shift), less the grace (§1); with no in time it is
     * on time (§13). The day cost is the day's pay: its day rate less what leave pay deducts, 0 when not employed (office
     * staff have no site costing, §8).
     *
     * @param  numeric-string  $minuteDivisor
     * @return array{int, numeric-string, DayLine}
     */
    private static function day(DayInput $d, Rates $r, string $minuteDivisor): array
    {
        $wage = $d->wage ?? '0';
        $late = $d->state === 'work' && $d->in !== null && $d->scheduledIn !== null
            ? DayRules::officeLateMinutes($d->in, $d->scheduledIn, $r->grace)
            : 0;
        $lateness = Money::div(Money::mul(Money::mul((string) $late, $wage), $r->multiplier), $minuteDivisor);
        $pay = $d->state === 'not_employed'
            ? '0'
            : Money::sub(Money::div($wage, $r->monthDays), Money::sum(...array_values(MonthlyBase::leave([$d], $r))));

        return [$late, $lateness, new DayLine($d->date, $d->siteId, [Component::Lateness->value => Money::round6($lateness)], Money::round6($pay), MonthlyBase::dayFlags($d))];
    }
}
