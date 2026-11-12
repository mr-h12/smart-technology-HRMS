<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** What every monthly type's line shares (office, engineers, permanent workers): the salary, not-employed days and leave pay. */
final class MonthlyBase
{
    /**
     * The salary pays monthDays day rates for the period, each calendar day at the salary in force that day: the days before
     * a change at the old salary ÷ monthDays and the rest of the monthDays at the new one, whatever the period's length.
     * Each not-employed day deducts one day rate, floored so the salary part is never below 0 (calculations §10).
     *
     * @param  list<DayInput>  $days  the period's days in date order
     * @return array{salary: numeric-string, not_employed: numeric-string} unrounded
     */
    public static function salary(array $days, Rates $r): array
    {
        $wages = self::wages($days);
        if ($wages === []) {
            return ['salary' => '0', 'not_employed' => '0'];
        }

        // Every day pays its own day rate, and the last day's rate makes up the period to monthDays days (a 31-day period
        // gives one back), so a period at one salary pays exactly that salary.
        $fillDays = Money::sub($r->monthDays, (string) count($wages));
        $salary = Money::div(Money::add(Money::sum(...$wages), Money::mul($fillDays, $wages[array_key_last($wages)])), $r->monthDays);

        $notEmployed = '0';
        foreach ($days as $i => $d) {
            if ($d->state === 'not_employed') {
                $notEmployed = Money::add($notEmployed, $wages[$i]);
            }
        }
        $notEmployed = Money::div($notEmployed, $r->monthDays);

        return ['salary' => $salary, 'not_employed' => bccomp($notEmployed, $salary, 12) > 0 ? $salary : $notEmployed];
    }

    /**
     * Leave pay (calculations §7, R21): an absent (غ) day and an unpaid day each deduct the day's rate, reported apart; a sick
     * day deducts (100 − sick_leave_pay_percent)% of it. Every other leave day is paid (annual, casual, weekly off, holiday,
     * and rest and other, which no rule names: flagged by dayFlags).
     *
     * @param  list<DayInput>  $days
     * @return array{absence: numeric-string, unpaid: numeric-string, sick_cut: numeric-string} unrounded
     */
    public static function leave(array $days, Rates $r): array
    {
        $absence = $unpaid = $sickCut = '0';
        $sickShare = Money::div(Money::sub('100', $r->sickPercent), '100');
        foreach ($days as $d) {
            if ($d->state !== 'leave') {
                continue;
            }
            $dayRate = Money::div($d->wage ?? '0', $r->monthDays);
            if ($d->leaveCode === 'absent') {
                $absence = Money::add($absence, $dayRate);
            } elseif ($d->leaveCode === 'unpaid') {
                $unpaid = Money::add($unpaid, $dayRate);
            } elseif ($d->leaveCode === 'sick') {
                $sickCut = Money::add($sickCut, Money::mul($dayRate, $sickShare));
            }
        }

        return ['absence' => $absence, 'unpaid' => $unpaid, 'sick_cut' => $sickCut];
    }

    /**
     * The day flags every monthly type shares (design, "Flags"): a missing day, an employed day with no salary in force (paid
     * as 0), and a rest or other leave day, paid with no rule naming it, for HR to check (design, "Rules the engines follow").
     *
     * @return list<string>
     */
    public static function dayFlags(DayInput $d): array
    {
        $flags = [];
        if ($d->state === 'missing') {
            $flags[] = 'missing';
        }
        if ($d->state !== 'not_employed' && $d->wage === null) {
            $flags[] = 'no_wage';
        }
        if ($d->state === 'leave' && ($d->leaveCode === 'rest' || $d->leaveCode === 'other')) {
            $flags[] = 'leave_to_check:'.$d->leaveCode;
        }

        return $flags;
    }

    /**
     * Each day's salary for its day rate. An employed day has its own (0 with no pay row in force). A not-employed day has
     * the nearest employed day's: the one before it or, before the first, the first, so it deducts what the period pays,
     * whatever the assembler passes for a day the employee did not hold this type.
     *
     * @param  list<DayInput>  $days
     * @return list<numeric-string>
     */
    private static function wages(array $days): array
    {
        $wages = [];
        $last = null;
        foreach ($days as $d) {
            if ($d->state !== 'not_employed') {
                $last = $d->wage ?? '0';
            }
            $wages[] = $last;
        }
        $first = array_values(array_filter($wages, fn (?string $w) => $w !== null))[0] ?? '0';

        return array_map(fn (?string $w) => $w ?? $first, $wages);
    }
}
