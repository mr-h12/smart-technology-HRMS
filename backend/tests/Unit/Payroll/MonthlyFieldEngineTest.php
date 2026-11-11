<?php

namespace Tests\Unit\Payroll;

use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Domain\Component;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\Line;
use App\Modules\Payroll\Domain\MonthlyBase;
use App\Modules\Payroll\Domain\MonthlyFieldEngine;
use App\Modules\Payroll\Domain\Rates;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** docs/calculations.md §2 (engineers), §3 (permanent workers), §0a (lateness), §7 "Leave pay", §10. S = 9000: day rate 300, minute rate 0.625. */
class MonthlyFieldEngineTest extends TestCase
{
    public function test_engineer_day_extra(): void
    {
        $line = $this->engineer([$this->day(otMinutes: 60, lateMinutes: 25, outsideMinutes: 30)]);

        // Day extra 65.625 = 60 × 0.625 × 1.5 − (25 − 15) × 0.625 × 1.5 + 30 × 0.625
        $this->assertSame('56.250000', $this->component($line, Component::Overtime));
        $this->assertSame('9.375000', $this->component($line, Component::Lateness));
        $this->assertSame('18.750000', $this->component($line, Component::Outside));
        $this->assertSame('365.625000', $line->days[0]->dayCost);   // day rate 300 + day extra
    }

    public function test_engineer_transport_from_override_only(): void
    {
        $days = fn (string $fare) => [
            $this->day(date: '2026-10-01', fare: $fare),
            $this->day(date: '2026-10-02', fare: $fare),
            $this->day(date: '2026-10-03', state: 'leave', leaveCode: 'friday', fare: $fare),
        ];

        $this->assertSame('100.000000', $this->component($this->engineer($days('50')), Component::Transport));
        $this->assertSame('0.000000', $this->component($this->engineer($days('0')), Component::Transport));
    }

    public function test_permanent_worker_day(): void
    {
        $day = fn (string $transport) => $this->day(in: '09:20', out: '17:30', outsideAmount: '15', transport: $transport, fare: '40');
        $line = $this->worker([$day('full')]);

        $this->assertSame('6.250000', $this->component($line, Component::Lateness));    // (20 − 15) × 0.625 × 2
        $this->assertSame('84.375000', $this->component($line, Component::Overtime));   // 90 × 0.625 × 1.5, from the shift end
        $this->assertSame('15.000000', $this->component($line, Component::Outside));
        $this->assertSame('40.000000', $this->component($line, Component::Transport));
        $this->assertSame('399.375000', $line->days[0]->dayCost);                         // day rate 300 + OT money 84.375 + 15
        $this->assertSame('20.000000', $this->component($this->worker([$day('half')]), Component::Transport));
    }

    public function test_absence_deducts_a_day_rate(): void
    {
        // R21: a غ day deducts its day rate for every monthly type.
        $line = $this->engineer([$this->day(state: 'leave', leaveCode: 'absent')]);

        $this->assertSame('300.000000', $this->component($line, Component::Absence));
        $this->assertSame('0.000000', $line->days[0]->dayCost);
        $this->assertSame('8700.000000', $this->component($line, Component::Net));
    }

    public function test_leave_pay(): void
    {
        $paid = $this->engineer([$this->day(state: 'leave', leaveCode: 'annual'), $this->day(state: 'leave', leaveCode: 'casual')]);
        foreach ([Component::Absence, Component::UnpaidLeave, Component::SickCut] as $c) {
            $this->assertSame('0.000000', $this->component($paid, $c));
        }
        $this->assertSame('9000.000000', $this->component($paid, Component::Net));

        $unpaid = $this->engineer([$this->day(state: 'leave', leaveCode: 'unpaid')]);
        $this->assertSame('300.000000', $this->component($unpaid, Component::UnpaidLeave));
        $this->assertSame('0.000000', $this->component($unpaid, Component::Absence));

        $sick = $this->engineer([$this->day(state: 'leave', leaveCode: 'sick')], sickPercent: '75');
        $this->assertSame('75.000000', $this->component($sick, Component::SickCut));
        $this->assertSame('225.000000', $sick->days[0]->dayCost);
    }

    public function test_rest_and_other_leave_are_paid_and_flagged(): void
    {
        $line = $this->worker([$this->day(state: 'leave', leaveCode: 'rest'), $this->day(state: 'leave', leaveCode: 'other')]);

        $this->assertSame(['leave_to_check:rest'], $line->days[0]->flags);
        $this->assertSame(['leave_to_check:other'], $line->days[1]->flags);
        $this->assertSame(['leave_to_check'], $line->flags);
        $this->assertSame('9000.000000', $this->component($line, Component::Net));
    }

    public function test_salary_change_mid_period(): void
    {
        // §10: the days before the change at 9000 ÷ 30, the rest of the 30 at 12000 ÷ 30: 10 × 300 + 20 × 400.
        $days = fn (int $length) => array_map(
            fn (int $i) => $this->day(date: $this->date('2026-09-26', $i), wage: $i < 10 ? '9000' : '12000'),
            range(0, $length - 1),
        );

        $this->assertSame('11000.000000', $this->component($this->engineer($days(30)), Component::Salary));
        $this->assertSame('11000.000000', $this->component($this->engineer($days(31)), Component::Salary));   // calendar days, never 31 rates
    }

    public function test_not_employed_days_deduct_a_day_rate_each(): void
    {
        // Hired on the 11th day of a 31-day period (no pay row before the hire), terminated after the 25th.
        $days = array_map(fn (int $i) => $this->day(
            date: $this->date('2026-10-26', $i),
            state: $i < 10 || $i >= 25 ? 'not_employed' : 'work',
            wage: $i < 10 ? null : '9000',
        ), range(0, 30));
        $line = $this->engineer($days);

        $this->assertSame('9000.000000', $this->component($line, Component::Salary));
        $this->assertSame('4800.000000', $this->component($line, Component::NotEmployed));   // 16 × 300
        $this->assertSame('4200.000000', $this->component($line, Component::Net));
        $this->assertSame('0.000000', $line->days[0]->dayCost);
        $this->assertSame([], $line->flags);

        // Hired on the last day of a 31-day period: 30 not-employed days take the whole salary, never more.
        $late = array_map(fn (int $i) => $this->day(date: $this->date('2026-10-26', $i), state: $i < 30 ? 'not_employed' : 'work'), range(0, 30));
        $this->assertSame('9000.000000', $this->component($this->engineer($late), Component::NotEmployed));

        // The floor itself, which month_days 30 never reaches: at 28, those 30 days would deduct 9,642.86 from a 9,000 salary.
        $floored = MonthlyBase::salary($late, new Rates(monthDays: '28', dayHours: '8', overtime: '1.5', grace: 15, multiplier: '1.5', sickPercent: '100'));
        $this->assertSame($floored['salary'], $floored['not_employed']);
    }

    public function test_insured_basic_split(): void
    {
        $days = [$this->day(otMinutes: 60)];
        $line = $this->engineer($days, ['insured_basic' => '5000']);

        $this->assertSame('5000.000000', $this->component($line, Component::InsuredBasic));
        $this->assertSame('4000.000000', $this->component($line, Component::Incentives));
        $this->assertSame($this->component($this->engineer($days), Component::Net), $this->component($line, Component::Net));
    }

    public function test_open_missing_and_no_wage_days(): void
    {
        // An open day is a worked day without overtime (it has no departure yet); a missing day is paid by the salary.
        $engineer = $this->engineer([
            $this->day(date: '2026-10-01', state: 'open', out: null, otMinutes: 60, lateMinutes: 25, fare: '50'),
            $this->day(date: '2026-10-02', state: 'missing', fare: '50'),
        ]);
        [$open, $missing] = $engineer->days;
        $this->assertSame(['open_day'], $open->flags);
        $this->assertSame('0.000000', $open->parts[Component::Overtime->value]);
        $this->assertSame('9.375000', $open->parts[Component::Lateness->value]);
        $this->assertSame('50.000000', $open->parts[Component::Transport->value]);
        $this->assertSame(['missing'], $missing->flags);
        $this->assertSame('0.000000', $missing->parts[Component::Transport->value]);
        $this->assertSame(['open_day', 'missing'], $engineer->flags);
        $this->assertSame('9040.625000', $this->component($engineer, Component::Net));   // 9000 + 50 − 9.375

        $worker = $this->worker([$this->day(state: 'open', in: '09:20', out: null)]);
        $this->assertSame('6.250000', $this->component($worker, Component::Lateness));
        $this->assertSame('0.000000', $this->component($worker, Component::Overtime));

        $noWage = $this->worker([$this->day(wage: null, in: '09:20', out: '17:30')]);
        $this->assertSame(['no_wage'], $noWage->days[0]->flags);
        $this->assertSame('0.000000', $this->component($noWage, Component::Overtime));
    }

    public function test_early_departure_is_flagged_not_deducted(): void
    {
        // R25: shown to HR, never deducted.
        $engineer = $this->engineer([$this->day(out: '15:00')]);
        $this->assertSame(['early_departure:60'], $engineer->days[0]->flags);
        $this->assertSame(['early_departure'], $engineer->flags);
        $this->assertSame('9000.000000', $this->component($engineer, Component::Net));

        // A night shift: 03:00 is the next day, an hour before the 04:00 end; a day shift worked past midnight is overtime.
        $night = $this->worker([$this->day(in: '20:00', out: '03:00', scheduledIn: '20:00', scheduledOut: '04:00')]);
        $this->assertSame(['early_departure:60'], $night->days[0]->flags);
        $late = $this->worker([$this->day(out: '01:00')]);
        $this->assertSame([], $late->days[0]->flags);
        $this->assertSame('506.250000', $this->component($late, Component::Overtime));   // 540 × 0.625 × 1.5
    }

    public function test_negative_net_is_flagged_not_clipped(): void
    {
        $line = $this->worker([$this->day()], ['deduction' => '9500']);

        $this->assertSame('-500.000000', $this->component($line, Component::Net));
        $this->assertSame(['negative_net'], $line->flags);
    }

    public function test_net(): void
    {
        // §2: salary + Σ day extra + Σ transport − absence − deduction − insurance − tax − advances.
        $engineer = $this->engineer([
            $this->day(date: '2026-10-01', otMinutes: 60, lateMinutes: 25, outsideMinutes: 30, fare: '50', advance: '100'),
            $this->day(date: '2026-10-02', state: 'leave', leaveCode: 'absent'),
            $this->day(date: '2026-10-03', state: 'leave', leaveCode: 'friday', advance: '50'),
        ], ['deduction' => '75', 'insurance_employee' => '400', 'income_tax' => '120', 'insurance_company' => '700', 'insured_basic' => '5000']);

        $this->assertSame([
            'salary' => '9000.000000',
            'transport' => '50.000000',
            'overtime' => '56.250000',
            'outside' => '18.750000',
            'lateness' => '9.375000',
            'absence' => '300.000000',
            'unpaid_leave' => '0.000000',
            'sick_cut' => '0.000000',
            'not_employed' => '0.000000',
            'deduction' => '75.000000',
            'insurance_employee' => '400.000000',
            'income_tax' => '120.000000',
            'advances' => '150.000000',
            'insurance_company' => '700.000000',   // a company cost, not in the net
            'insured_basic' => '5000.000000',
            'incentives' => '4000.000000',
            // 9000 + 50 + 56.25 + 18.75 − 9.375 − 300 − 75 − 400 − 120 − 150
            'net' => '8070.625000',
        ], $engineer->components);

        // §3: salary + Σ OT money + Σ transport − Σ lateness − unpaid leave − deduction − insurance − advances; blank tax = 0.
        $days = [
            $this->day(date: '2026-10-01', in: '09:20', out: '17:30', outsideAmount: '15', transport: 'half', fare: '40', advance: '100'),
            $this->day(date: '2026-10-02', state: 'leave', leaveCode: 'unpaid', transport: 'none'),
            $this->day(date: '2026-10-03', state: 'leave', leaveCode: 'friday', transport: 'none'),
        ];
        $worker = $this->worker($days, ['deduction' => '50', 'insurance_employee' => '400', 'income_tax' => null]);
        // 9000 + 84.375 + 15 + 20 − 6.25 − 300 − 50 − 400 − 100
        $this->assertSame('8263.125000', $this->component($worker, Component::Net));
        $this->assertSame('8713.125000', $this->component($this->worker($days), Component::Net));   // a blank entry deducts nothing
    }

    /**
     * @param  list<DayInput>  $days
     * @param  array<string, numeric-string|null>  $entry
     * @param  numeric-string  $sickPercent
     */
    private function engineer(array $days, array $entry = [], string $sickPercent = '100'): Line
    {
        return MonthlyFieldEngine::compute(EmployeeType::Engineer, $days, $this->rates('1.5', $sickPercent), $entry);
    }

    /**
     * @param  list<DayInput>  $days
     * @param  array<string, numeric-string|null>  $entry
     */
    private function worker(array $days, array $entry = []): Line
    {
        return MonthlyFieldEngine::compute(EmployeeType::PermanentWorker, $days, $this->rates('2', '100'), $entry);
    }

    /**
     * @param  numeric-string  $multiplier
     * @param  numeric-string  $sickPercent
     */
    private function rates(string $multiplier, string $sickPercent): Rates
    {
        return new Rates(monthDays: '30', dayHours: '8', overtime: '1.5', grace: 15, multiplier: $multiplier, sickPercent: $sickPercent);
    }

    private function component(Line $line, Component $c): string
    {
        return $line->components[$c->value];
    }

    private function date(string $start, int $plusDays): string
    {
        return (new DateTimeImmutable($start))->modify("+{$plusDays} day")->format('Y-m-d');
    }

    /**
     * A worked day at a work site on the 09:00–16:00 field shift, salary 9000, nothing extra, unless told otherwise.
     *
     * @param  numeric-string|null  $outsideAmount
     * @param  numeric-string|null  $wage
     * @param  numeric-string  $fare
     * @param  numeric-string|null  $advance
     */
    private function day(
        string $date = '2026-10-01',
        string $state = 'work',
        ?string $leaveCode = null,
        ?string $in = '09:00',
        ?string $out = '16:00',
        string $scheduledIn = '09:00',
        string $scheduledOut = '16:00',
        int $lateMinutes = 0,
        int $otMinutes = 0,
        int $outsideMinutes = 0,
        ?string $outsideAmount = null,
        string $transport = 'full',
        ?string $wage = '9000',
        string $fare = '0',
        ?string $advance = null,
    ): DayInput {
        $leave = $leaveCode !== null;

        return new DayInput(
            date: $date, state: $state, leaveCode: $leaveCode, siteId: $leave ? 'leave-'.$leaveCode : 'site-1',
            in: $leave ? null : $in, out: $leave ? null : $out,
            scheduledIn: $leave ? null : $scheduledIn, scheduledOut: $leave ? null : $scheduledOut,
            lateMinutes: $lateMinutes, otMinutes: $otMinutes, outsideMinutes: $outsideMinutes, outsideAmount: $outsideAmount,
            transport: $transport, advance: $advance, wage: $wage, allowance: '0', fare: $fare,
        );
    }
}
