<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Domain\Component;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\Line;
use App\Modules\Payroll\Domain\OfficeEngine;
use App\Modules\Payroll\Domain\Rates;
use PHPUnit\Framework\TestCase;

/** docs/calculations.md §1 (office), §0a (lateness), §7 "Leave pay", §10. S = 9000: day rate 300, minute rate 0.625. */
class OfficeEngineTest extends TestCase
{
    public function test_lateness_absence_overtime(): void
    {
        // Chargeable late minutes = in − shift start − 15 grace, per day: 25 + 35 + 0 + 0 = 60.
        $line = $this->office([
            $this->day(1, in: '09:40'),
            $this->day(2, in: '10:50', scheduledIn: '10:00'),   // the employee's own shift
            $this->day(3, in: '09:15'),                         // within the grace
            $this->day(4, in: null),                            // present with no in time: on time (§13)
            $this->day(5, state: 'leave', leaveCode: 'absent'),
            $this->day(6, state: 'leave', leaveCode: 'absent'),
        ], ['ot_minutes' => 120]);

        $this->assertSame('37.500000', $this->component($line, Component::Lateness));   // 60 × 0.625 × 1
        $this->assertSame('600.000000', $this->component($line, Component::Absence));   // 2 × 300
        $this->assertSame('112.500000', $this->component($line, Component::Overtime));  // 120 × 0.625 × 1.5
        $this->assertSame('8475.000000', $this->component($line, Component::Net));
        $this->assertSame('15.625000', $line->days[0]->parts[Component::Lateness->value]);
        $this->assertSame('0.000000', $line->days[4]->dayCost);
    }

    public function test_raise_is_paid(): void
    {
        // Excel error #2: the sheet's net took the basic, not basic + raise. The assembler passes basic 9000 + raise 500 as the wage.
        $line = $this->office([$this->day(1, wage: '9500')]);

        $this->assertSame('9500.000000', $this->component($line, Component::Salary));
        $this->assertSame('9500.000000', $this->component($line, Component::Net));
    }

    public function test_exemption_reduces_late_minutes(): void
    {
        $days = [$this->day(1, in: '09:55'), $this->day(2, in: '09:35')];   // 40 + 20 chargeable

        $this->assertSame('25.000000', $this->component($this->office($days, ['lateness_exemption_minutes' => 20]), Component::Lateness));   // 40 × 0.625
        $this->assertSame('0.000000', $this->component($this->office($days, ['lateness_exemption_minutes' => 90]), Component::Lateness));   // never below 0

        // Each late minute at its day's salary (§0a), the exemption waiving the same share of each: 20 at 9000 and 20 at 12000.
        $changed = [$this->day(1, in: '09:35'), $this->day(20, in: '09:35', wage: '12000')];
        $this->assertSame('29.166667', $this->component($this->office($changed), Component::Lateness));   // 12.5 + 16.666…
        $this->assertSame('14.583333', $this->component($this->office($changed, ['lateness_exemption_minutes' => 20]), Component::Lateness));
    }

    public function test_travel_and_tower(): void
    {
        $line = $this->office([$this->day(1)], ['travel_days' => 2, 'tower_days' => 4]);

        $this->assertSame('600.000000', $this->component($line, Component::Travel));   // 2 × 300
        $this->assertSame('300.000000', $this->component($line, Component::Tower));    // 9000 ÷ 30 ÷ 4 × 4
        $this->assertSame('9900.000000', $this->component($line, Component::Net));
    }

    public function test_hired_mid_month(): void
    {
        $line = $this->office($this->october(first: 11, last: 31));

        $this->assertSame('9000.000000', $this->component($line, Component::Salary));
        $this->assertSame('3000.000000', $this->component($line, Component::NotEmployed));   // Oct 1–10: 10 × 300
        $this->assertSame('6000.000000', $this->component($line, Component::Net));
        $this->assertSame('0.000000', $line->days[0]->dayCost);
    }

    public function test_leaver_negative_net(): void
    {
        // Review Focus 5: terminated Oct 5, an advance larger than what is left: the net is shown below 0, never clipped.
        $line = $this->office($this->october(first: 1, last: 5), ['advances' => '5000']);

        $this->assertSame('7800.000000', $this->component($line, Component::NotEmployed));   // Oct 6–31: 26 × 300
        $this->assertSame('-3800.000000', $this->component($line, Component::Net));
        $this->assertSame(['negative_net'], $line->flags);
    }

    public function test_not_employed_floors_the_salary_at_zero(): void
    {
        $line = $this->office($this->october(first: 31, last: 31));

        $this->assertSame('9000.000000', $this->component($line, Component::NotEmployed));   // Oct 1–30: 30 × 300, the whole salary
        $this->assertSame('0.000000', bcsub($this->component($line, Component::Salary), $this->component($line, Component::NotEmployed), 6));
        $this->assertSame('0.000000', $this->component($line, Component::Net));
        $this->assertSame([], $line->flags);
    }

    public function test_leave_pay_and_flags(): void
    {
        // §7 and §2a: weekly off, holidays, annual and casual are paid; rest and other are paid and flagged for HR.
        $line = $this->office([
            $this->day(1, state: 'leave', leaveCode: 'friday'),
            $this->day(2, state: 'leave', leaveCode: 'saturday'),
            $this->day(3, state: 'leave', leaveCode: 'official_holiday'),
            $this->day(4, state: 'leave', leaveCode: 'annual'),
            $this->day(5, state: 'leave', leaveCode: 'casual'),
            $this->day(6, state: 'leave', leaveCode: 'rest'),
            $this->day(7, state: 'leave', leaveCode: 'other'),
            $this->day(8, state: 'missing'),
            $this->day(9, state: 'leave', leaveCode: 'unpaid'),
            $this->day(10, state: 'leave', leaveCode: 'sick'),
        ], sickPercent: '75');

        $this->assertSame('0.000000', $this->component($line, Component::Absence));
        $this->assertSame('300.000000', $this->component($line, Component::UnpaidLeave));
        $this->assertSame('75.000000', $this->component($line, Component::SickCut));
        $this->assertSame('8625.000000', $this->component($line, Component::Net));
        $this->assertSame('225.000000', $line->days[9]->dayCost);
        $this->assertSame(['leave_to_check:rest'], $line->days[5]->flags);
        $this->assertSame(['missing'], $line->days[7]->flags);
        $this->assertSame(['leave_to_check', 'missing'], $line->flags);
    }

    public function test_net(): void
    {
        $days = [$this->day(1, in: '09:40'), $this->day(2, state: 'leave', leaveCode: 'absent')];   // 25 chargeable minutes
        $line = $this->office($days, [
            'lateness_exemption_minutes' => 5, 'ot_minutes' => 60, 'travel_days' => 1, 'tower_days' => 2, 'bonus' => '500',
            'negligence' => '100', 'advances' => '1000', 'insurance_employee' => '400', 'income_tax' => '120',
            'insurance_company' => '700', 'insured_basic' => '5000',
        ]);

        $this->assertSame([
            'salary' => '9000.000000',
            'travel' => '300.000000',
            'tower' => '150.000000',
            'overtime' => '56.250000',
            'bonus' => '500.000000',
            'lateness' => '12.500000',             // (25 − 5) × 0.625
            'absence' => '300.000000',
            'unpaid_leave' => '0.000000',
            'sick_cut' => '0.000000',
            'not_employed' => '0.000000',
            'negligence' => '100.000000',
            'insurance_employee' => '400.000000',
            'income_tax' => '120.000000',
            'advances' => '1000.000000',
            'insurance_company' => '700.000000',   // a company cost, not in the net
            'insured_basic' => '5000.000000',
            'incentives' => '4000.000000',
            // §1: 9000 + 300 + 150 + 56.25 + 500 − 12.5 − 300 − 100 − 400 − 120 − 1000
            'net' => '8073.750000',
        ], $line->components);

        $blank = $this->office($days, ['lateness_exemption_minutes' => null, 'ot_minutes' => null, 'bonus' => null, 'advances' => null]);
        $this->assertSame('8684.375000', $this->component($blank, Component::Net));   // 9000 − 15.625 − 300: blank is 0
    }

    /**
     * @param  list<DayInput>  $days
     * @param  array<string, int|numeric-string|null>  $entry
     * @param  numeric-string  $sickPercent
     */
    private function office(array $days, array $entry = [], string $sickPercent = '100'): Line
    {
        $rates = new Rates(monthDays: '30', dayHours: '8', overtime: '1.5', grace: 15, multiplier: '1', sickPercent: $sickPercent);

        return OfficeEngine::compute($days, $rates, $entry);
    }

    private function component(Line $line, Component $c): string
    {
        return $line->components[$c->value];
    }

    /**
     * October 2026: present at 9000 from the first employed day through the last, not employed outside them.
     *
     * @return list<DayInput>
     */
    private function october(int $first, int $last): array
    {
        return array_map(
            fn (int $n) => $n < $first || $n > $last ? $this->day($n, state: 'not_employed', wage: null) : $this->day($n),
            range(1, 31),
        );
    }

    /**
     * An October day on the 09:00–17:00 office shift at salary 9000; present days arrive on time unless told otherwise.
     *
     * @param  numeric-string|null  $wage
     */
    private function day(int $n, string $state = 'work', ?string $leaveCode = null, ?string $in = '09:00', string $scheduledIn = '09:00', ?string $wage = '9000'): DayInput
    {
        $present = $state === 'work';

        return new DayInput(
            date: sprintf('2026-10-%02d', $n), state: $state, leaveCode: $leaveCode, siteId: $leaveCode === null ? null : 'leave-'.$leaveCode,
            in: $present ? $in : null, out: $present && $in !== null ? '17:00' : null, scheduledIn: $scheduledIn, scheduledOut: '17:00',
            lateMinutes: 0, otMinutes: 0, outsideMinutes: 0, outsideAmount: null, transport: 'none', advance: null, wage: $wage,
            allowance: '0', fare: '0',
        );
    }
}
