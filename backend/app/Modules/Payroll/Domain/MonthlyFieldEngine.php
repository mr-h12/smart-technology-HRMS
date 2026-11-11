<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Employees\Domain\EmployeeType;
use InvalidArgumentException;

/** An engineer's or a permanent worker's line for one 26th → 25th period: calculations §2, §3, lateness §0a, leave pay §7, §10. */
final class MonthlyFieldEngine
{
    /**
     * @param  list<DayInput>  $days  the period's days in date order
     * @param  array{deduction?: numeric-string|null, insurance_employee?: numeric-string|null, insurance_company?: numeric-string|null, income_tax?: numeric-string|null, insured_basic?: numeric-string|null}  $entry  HR's inputs for the period and the pay row's insured basic; blank = 0
     */
    public static function compute(EmployeeType $type, array $days, Rates $r, array $entry): Line
    {
        if ($type !== EmployeeType::Engineer && $type !== EmployeeType::PermanentWorker) {
            throw new InvalidArgumentException("Not a monthly field type: {$type->value}");
        }
        $engineer = $type === EmployeeType::Engineer;
        $minuteDivisor = Money::mul(Money::mul($r->monthDays, $r->dayHours), '60');   // the salary's minute divisor, 14,400 = 30 × 8 × 60
        $sum = self::parts(null);
        $dayLines = [];
        foreach ($days as $d) {
            [$parts, $dayCost, $flags] = self::day($engineer, $d, $r, $minuteDivisor);
            foreach ($parts as $c => $amount) {
                $sum[$c] = Money::add($sum[$c], $amount);
            }
            $dayLines[] = new DayLine($d->date, $d->siteId, array_map(Money::round6(...), $parts), Money::round6($dayCost), $flags);
        }

        ['salary' => $salary, 'not_employed' => $notEmployed] = MonthlyBase::salary($days, $r);
        ['absence' => $absence, 'unpaid' => $unpaid, 'sick_cut' => $sickCut] = MonthlyBase::leave($days, $r);
        $deduction = $entry['deduction'] ?? '0';
        $insuranceEmployee = $entry['insurance_employee'] ?? '0';
        $incomeTax = $entry['income_tax'] ?? '0';
        $insuredBasic = $entry['insured_basic'] ?? '0';
        // §2 and §3: an engineer's day extra is overtime + outside − lateness, a permanent worker's OT money is overtime + outside.
        $net = Money::sub(
            Money::sum($salary, $sum['transport'], $sum['overtime'], $sum['outside']),
            Money::sum($notEmployed, $sum['lateness'], $absence, $unpaid, $sickCut, $deduction, $insuranceEmployee, $incomeTax, $sum['advances']),
        );
        $components = [
            Component::Salary->value => $salary,
            Component::Transport->value => $sum['transport'],
            Component::Overtime->value => $sum['overtime'],
            Component::Outside->value => $sum['outside'],
            Component::Lateness->value => $sum['lateness'],
            Component::Absence->value => $absence,
            Component::UnpaidLeave->value => $unpaid,
            Component::SickCut->value => $sickCut,
            Component::NotEmployed->value => $notEmployed,
            Component::Deduction->value => $deduction,
            Component::InsuranceEmployee->value => $insuranceEmployee,
            Component::IncomeTax->value => $incomeTax,
            Component::Advances->value => $sum['advances'],
            Component::InsuranceCompany->value => $entry['insurance_company'] ?? '0',   // a company cost (§9), not in the net
            Component::InsuredBasic->value => $insuredBasic,
            Component::Incentives->value => Money::sub($salary, $insuredBasic),         // the payslip's split of the salary (§1)
            Component::Net->value => $net,
        ];

        return Line::computed($components, $dayLines);
    }

    /**
     * A worked day (an open one too, without overtime: it has no departure yet) earns its extras; any other day carries only
     * its advance. The day cost (§8) is the day's pay: on a worked day the day rate plus an engineer's day extra (§2) or a
     * permanent worker's OT money (§3); on a leave day the day rate less what leave pay deducts; on a missing or future day the
     * day rate, which the salary pays; on a not-employed day 0.
     *
     * @param  numeric-string  $minuteDivisor
     * @return array{array{transport: numeric-string, overtime: numeric-string, outside: numeric-string, lateness: numeric-string, advances: numeric-string}, numeric-string, list<string>}
     */
    private static function day(bool $engineer, DayInput $d, Rates $r, string $minuteDivisor): array
    {
        $flags = MonthlyBase::dayFlags($d);
        $dayRate = Money::div($d->wage ?? '0', $r->monthDays);
        if ($d->state !== 'work' && $d->state !== 'open') {
            $cost = match ($d->state) {
                'not_employed' => '0',
                'leave' => Money::sub($dayRate, Money::sum(...array_values(MonthlyBase::leave([$d], $r)))),
                default => $dayRate,
            };

            return [self::parts($d->advance), $cost, $flags];
        }

        if ($d->state === 'open') {
            $flags[] = 'open_day';
        }
        // R25: leaving before the shift end is shown to HR, never deducted. A time earlier than its start is the next day (§3).
        if ($d->in !== null && $d->out !== null && $d->scheduledIn !== null && $d->scheduledOut !== null) {
            $early = DayRules::endMinutes($d->scheduledIn, $d->scheduledOut) - DayRules::endMinutes($d->in, $d->out);
            if ($early > 0) {
                $flags[] = 'early_departure:'.$early;
            }
        }

        $wage = $d->wage ?? '0';
        // Engineers: the stored minutes (late raw, OT as HR approved). Permanent workers: from the times (§3, question 20).
        [$late, $ot] = $engineer ? [$d->lateMinutes, $d->otMinutes] : self::workerMinutes($d);
        $parts = [
            ...self::parts($d->advance),
            // Engineers: the fare (only the per-worker override, R2) in full on every day worked (§2). Permanent workers: the
            // fare for full, half of it for half (§3).
            'transport' => match ($engineer ? 'full' : $d->transport) {
                'full' => $d->fare,
                'half' => Money::div($d->fare, '2'),
                'none' => '0',
            },
            'overtime' => self::atMinuteRate($d->state === 'open' ? 0 : $ot, $wage, $r->overtime, $minuteDivisor),
            // Engineers: outside-Alexandria minutes at the minute rate with no multiplier (§2). Permanent workers: the money stored.
            'outside' => $engineer ? self::atMinuteRate($d->outsideMinutes, $wage, '1', $minuteDivisor) : ($d->outsideAmount ?? '0'),
            'lateness' => self::atMinuteRate(max(0, $late - $r->grace), $wage, $r->multiplier, $minuteDivisor),
        ];
        $cost = Money::sum($dayRate, $parts['overtime'], $parts['outside']);

        return [$parts, $engineer ? Money::sub($cost, $parts['lateness']) : $cost, $flags];
    }

    /**
     * A permanent worker's late and OT minutes from the day's times (§3); no overtime without a departure.
     *
     * @return array{int, int}
     */
    private static function workerMinutes(DayInput $d): array
    {
        if ($d->scheduledIn === null || $d->in === null) {
            return [0, 0];
        }
        if ($d->scheduledOut === null || $d->out === null) {
            return [DayRules::lateMinutes($d->scheduledIn, $d->in), 0];
        }
        $minutes = DayRules::workerMinutes($d->scheduledIn, $d->scheduledOut, $d->in, $d->out);

        return [$minutes['late'], $minutes['ot']];
    }

    /**
     * Minutes × the minute rate × a multiplier (§0): minutes × salary × multiplier ÷ (monthDays × dayHours × 60), divided once.
     *
     * @param  numeric-string  $wage
     * @param  numeric-string  $multiplier
     * @param  numeric-string  $minuteDivisor
     * @return numeric-string
     */
    private static function atMinuteRate(int $minutes, string $wage, string $multiplier, string $minuteDivisor): string
    {
        return Money::div(Money::mul(Money::mul((string) $minutes, $wage), $multiplier), $minuteDivisor);
    }

    /**
     * A day's parts, keyed by Component value; zero but for the advance.
     *
     * @param  numeric-string|null  $advance
     * @return array{transport: numeric-string, overtime: numeric-string, outside: numeric-string, lateness: numeric-string, advances: numeric-string}
     */
    private static function parts(?string $advance): array
    {
        return ['transport' => '0', 'overtime' => '0', 'outside' => '0', 'lateness' => '0', 'advances' => $advance ?? '0'];
    }
}
