<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Domain\Component;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\DayLaborerEngine;
use App\Modules\Payroll\Domain\Line;
use App\Modules\Payroll\Domain\Rates;
use PHPUnit\Framework\TestCase;

/** docs/calculations.md §4 (day laborers) and §0a (lateness). */
class DayLaborerEngineTest extends TestCase
{
    public function test_a_worked_day(): void
    {
        $line = $this->compute([$this->day(otMinutes: 60, fare: '35')]);

        $this->assertSame('426.875000', $line->days[0]->dayCost);
        $this->assertSame('330.000000', $this->component($line, Component::Wages));
        $this->assertSame('35.000000', $this->component($line, Component::Transport));
        $this->assertSame('61.875000', $this->component($line, Component::Overtime));
        $this->assertSame('426.875000', $this->component($line, Component::Net));
    }

    public function test_lateness_after_grace(): void
    {
        $this->assertSame('6.875000', $this->component($this->compute([$this->day(lateMinutes: 20)]), Component::Lateness));
        $this->assertSame('0.000000', $this->component($this->compute([$this->day(lateMinutes: 15)]), Component::Lateness));
    }

    public function test_allowance_share(): void
    {
        $line = $this->compute([$this->day(allowance: '82.5')]);

        $this->assertSame('82.500000', $this->component($line, Component::Allowance));
        $this->assertSame('412.500000', $line->days[0]->dayCost);
    }

    public function test_leave_and_absent_days_earn_nothing(): void
    {
        $line = $this->compute([
            $this->day(state: 'leave', leaveCode: 'friday', otMinutes: 60, allowance: '82.5', fare: '35'),
            $this->day(state: 'leave', leaveCode: 'absent', otMinutes: 60, allowance: '82.5', fare: '35'),
        ]);

        foreach ($line->days as $day) {
            $this->assertSame(['0.000000'], array_values(array_unique($day->parts)));
            $this->assertSame('0.000000', $day->dayCost);
        }
        $this->assertSame('0.000000', $this->component($line, Component::Transport));
        $this->assertSame('0.000000', $this->component($line, Component::Net));
    }

    public function test_a_not_employed_day_earns_nothing_but_keeps_its_advance(): void
    {
        $line = $this->compute([$this->day(state: 'not_employed', otMinutes: 60, fare: '35', advance: '40')]);

        $this->assertSame('0.000000', $line->days[0]->dayCost);
        $this->assertSame('40.000000', $line->days[0]->parts[Component::Advances->value]);
        $this->assertSame('-40.000000', $this->component($line, Component::Net));
    }

    public function test_negative_net_is_flagged_not_clipped(): void
    {
        $line = $this->compute([$this->day()], ['deduction' => '500']);

        $this->assertSame('-170.000000', $this->component($line, Component::Net));
        $this->assertSame(['negative_net'], $line->flags);
    }

    public function test_missing_and_open_days_are_flagged(): void
    {
        $line = $this->compute([
            $this->day(date: '2026-10-01', state: 'missing', otMinutes: 60, fare: '35'),
            $this->day(date: '2026-10-02', state: 'open', out: null, otMinutes: 60),
        ]);
        [$missing, $open] = $line->days;

        $this->assertSame(['missing'], $missing->flags);
        $this->assertSame('0.000000', $missing->dayCost);
        $this->assertSame(['open_day'], $open->flags);
        $this->assertSame('330.000000', $open->parts[Component::Wages->value]);
        $this->assertSame('0.000000', $open->parts[Component::Overtime->value]);
        $this->assertSame(['missing', 'open_day'], $line->flags);
        $this->assertSame('330.000000', $this->component($line, Component::Net));
    }

    public function test_early_departure_is_flagged_not_deducted(): void
    {
        $line = $this->compute([$this->day(out: '15:00')]);

        $this->assertSame(['early_departure:60'], $line->days[0]->flags);
        $this->assertSame(['early_departure'], $line->flags);
        $this->assertSame('330.000000', $this->component($line, Component::Net));
    }

    public function test_no_wage_on_a_worked_day(): void
    {
        // Review Focus 2: no pay row in force that day is wage 0 and a flag, never a crash or a skipped day.
        $line = $this->compute([$this->day(wage: null, otMinutes: 60, lateMinutes: 30, fare: '35')]);

        $this->assertCount(1, $line->days);
        $this->assertSame(['no_wage'], $line->days[0]->flags);
        $this->assertSame('0.000000', $this->component($line, Component::Wages));
        $this->assertSame('35.000000', $this->component($line, Component::Net));   // only the wage is missing; the fare is still paid
    }

    public function test_net(): void
    {
        $days = [
            $this->day(date: '2026-10-01', otMinutes: 60, lateMinutes: 20, allowance: '82.5', fare: '35', advance: '100'),
            $this->day(date: '2026-10-02', wage: '350', otMinutes: 30, fare: '35'),
            $this->day(date: '2026-10-03', state: 'leave', leaveCode: 'friday', advance: '50'),
        ];
        $line = $this->compute($days, ['bonus' => '200', 'deduction' => '75']);

        $this->assertSame([
            'wages' => '680.000000',
            'allowance' => '82.500000',
            'transport' => '70.000000',
            'overtime' => '94.687500',      // 61.875 + 30 × 350 ÷ 480 × 1.5
            'bonus' => '200.000000',
            'lateness' => '6.875000',
            'advances' => '150.000000',
            'deduction' => '75.000000',
            // 680 + 82.5 + 70 + 94.6875 + 200 − 6.875 − 150 − 75
            'net' => '895.312500',
        ], $line->components);
        $this->assertSame('100.000000', $line->days[0]->parts[Component::Advances->value]);

        // A blank entry deducts and adds nothing.
        $this->assertSame('770.312500', $this->component($this->compute($days, ['bonus' => null]), Component::Net));
    }

    /**
     * @param  list<DayInput>  $days
     * @param  array{bonus?: numeric-string|null, deduction?: numeric-string|null}  $entry
     */
    private function compute(array $days, array $entry = []): Line
    {
        return DayLaborerEngine::compute($days, new Rates(monthDays: '30', dayHours: '8', overtime: '1.5', grace: 15, multiplier: '2', sickPercent: '100'), $entry);
    }

    private function component(Line $line, Component $c): string
    {
        return $line->components[$c->value];
    }

    /**
     * A worked day at a work site on the 09:00–16:00 field shift, wage 330, no allowance or fare, unless told otherwise.
     *
     * @param  numeric-string|null  $wage
     * @param  numeric-string  $allowance
     * @param  numeric-string  $fare
     * @param  numeric-string|null  $advance
     */
    private function day(
        string $date = '2026-10-01',
        string $state = 'work',
        ?string $leaveCode = null,
        ?string $out = '16:00',
        int $lateMinutes = 0,
        int $otMinutes = 0,
        ?string $wage = '330',
        string $allowance = '0',
        string $fare = '0',
        ?string $advance = null,
    ): DayInput {
        return new DayInput(
            date: $date, state: $state, leaveCode: $leaveCode, siteId: $leaveCode === null ? 'site-1' : 'leave-'.$leaveCode,
            in: '09:00', out: $out, scheduledIn: '09:00', scheduledOut: '16:00',
            lateMinutes: $lateMinutes, otMinutes: $otMinutes, outsideMinutes: 0, outsideAmount: null, transport: 'full',
            advance: $advance, wage: $wage, allowance: $allowance, fare: $fare,
        );
    }
}
