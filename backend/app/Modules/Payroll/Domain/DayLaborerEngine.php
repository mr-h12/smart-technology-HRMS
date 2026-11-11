<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Modules\Attendance\Domain\DayRules;

/** A day laborer's line for one half-month period: calculations §4, lateness §0a. */
final class DayLaborerEngine
{
    /**
     * @param  list<DayInput>  $days
     * @param  array{bonus?: numeric-string|null, deduction?: numeric-string|null}  $entry  HR's inputs for the period; blank = 0
     */
    public static function compute(array $days, Rates $r, array $entry): Line
    {
        $dayMinutes = Money::mul($r->dayHours, '60');   // the daily wage's minute divisor, 480 = 8 × 60
        $sum = self::parts(null);
        $dayLines = [];
        foreach ($days as $d) {
            [$parts, $flags] = self::day($d, $r, $dayMinutes);
            foreach ($parts as $c => $amount) {
                $sum[$c] = Money::add($sum[$c], $amount);
            }
            $dayCost = Money::sub(Money::sum($parts['wages'], $parts['allowance'], $parts['transport'], $parts['overtime']), $parts['lateness']);
            $dayLines[] = new DayLine($d->date, $d->siteId, array_map(Money::round6(...), $parts), Money::round6($dayCost), $flags);
        }

        $bonus = $entry['bonus'] ?? '0';
        $deduction = $entry['deduction'] ?? '0';
        $net = Money::sub(
            Money::sum($sum['wages'], $sum['allowance'], $sum['transport'], $sum['overtime'], $bonus),
            Money::sum($sum['lateness'], $sum['advances'], $deduction),
        );
        $components = [
            Component::Wages->value => $sum['wages'],
            Component::Allowance->value => $sum['allowance'],
            Component::Transport->value => $sum['transport'],
            Component::Overtime->value => $sum['overtime'],
            Component::Bonus->value => $bonus,
            Component::Lateness->value => $sum['lateness'],
            Component::Advances->value => $sum['advances'],
            Component::Deduction->value => $deduction,
            Component::Net->value => $net,
        ];
        $flagNames = array_map(fn (string $f) => explode(':', $f)[0], array_merge(...array_column($dayLines, 'flags')));

        return (new Line(array_map(Money::round6(...), $components), $dayLines, array_values(array_unique($flagNames))))->withNegativeNetFlag();
    }

    /**
     * Only a worked day earns (an open day too, without overtime: it has no departure yet). Leave, missing, future and
     * not-employed days earn nothing, as a day laborer has no paid leave (§4); the day's advance is deducted whatever the day.
     *
     * @param  numeric-string  $dayMinutes
     * @return array{array<string, numeric-string>, list<string>}
     */
    private static function day(DayInput $d, Rates $r, string $dayMinutes): array
    {
        if ($d->state !== 'work' && $d->state !== 'open') {
            return [self::parts($d->advance), $d->state === 'missing' ? ['missing'] : []];
        }

        $flags = $d->state === 'open' ? ['open_day'] : [];
        if ($d->wage === null) {
            $flags[] = 'no_wage';
        }
        // R25: leaving early is shown to HR, never deducted; a day laborer's day never crosses midnight (out_before_in).
        if ($d->out !== null && $d->scheduledOut !== null && DayRules::minutes($d->out) < DayRules::minutes($d->scheduledOut)) {
            $flags[] = 'early_departure:'.(DayRules::minutes($d->scheduledOut) - DayRules::minutes($d->out));
        }

        $wage = $d->wage ?? '0';
        $ot = $d->state === 'open' ? 0 : $d->otMinutes;
        $chargeableLate = max(0, $d->lateMinutes - $r->grace);

        return [[
            ...self::parts($d->advance),
            'wages' => $wage,
            'allowance' => $d->allowance,
            // A day laborer's day stores no transport portion (DayRules::FIELDS), so a worked day pays the fare in full (§4).
            'transport' => $d->fare,
            'overtime' => Money::div(Money::mul(Money::mul((string) $ot, $wage), $r->overtime), $dayMinutes),
            'lateness' => Money::div(Money::mul(Money::mul((string) $chargeableLate, $wage), $r->multiplier), $dayMinutes),
        ], $flags];
    }

    /**
     * A day's parts, keyed by Component value; zero but for the advance.
     *
     * @param  numeric-string|null  $advance
     * @return array{wages: numeric-string, allowance: numeric-string, transport: numeric-string, overtime: numeric-string, lateness: numeric-string, advances: numeric-string}
     */
    private static function parts(?string $advance): array
    {
        return ['wages' => '0', 'allowance' => '0', 'transport' => '0', 'overtime' => '0', 'lateness' => '0', 'advances' => $advance ?? '0'];
    }
}
