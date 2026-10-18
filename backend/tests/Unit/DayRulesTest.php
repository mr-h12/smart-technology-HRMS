<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\OffDays;
use App\Modules\Employees\Domain\EmployeeType;
use PHPUnit\Framework\TestCase;

class DayRulesTest extends TestCase
{
    private const WORK = ['kind' => 'work', 'outside_alexandria' => true, 'active' => true];

    private const LEAVE = ['kind' => 'leave', 'outside_alexandria' => false, 'active' => true];

    public function test_each_type_accepts_its_fields_and_rejects_the_others(): void
    {
        $this->assertSame([], DayRules::violations(EmployeeType::Engineer, self::WORK, ['in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => 10, 'ot_minutes' => 60, 'outside_minutes' => 30, 'advance_amount' => '500', 'note' => 'n']));
        $this->assertSame([['field' => 'transport', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Engineer, self::WORK, ['transport' => 'full']));
        $this->assertSame([['field' => 'in_time', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['in_time' => '08:00']));
        $this->assertSame([['field' => 'late_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['late_minutes' => 5]));
        $this->assertSame([['field' => 'advance_amount', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Office, null, ['advance_amount' => '1']));
        $this->assertSame([['field' => 'site_id', 'code' => 'site_not_allowed']], DayRules::violations(EmployeeType::Office, self::WORK, []));
        $this->assertSame([], DayRules::violations(EmployeeType::Office, null, ['in_time' => '09:40', 'out_time' => '17:00']));
        $this->assertSame([['field' => 'site_id', 'code' => 'site_required']], DayRules::violations(EmployeeType::Engineer, null, []));
        $this->assertSame([['field' => 'site_id', 'code' => 'site_inactive']], DayRules::violations(EmployeeType::Engineer, [...self::WORK, 'active' => false], []));
        $this->assertSame([['field' => 'outside_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::Engineer, [...self::WORK, 'outside_alexandria' => false], ['outside_minutes' => 30]));
        $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['scheduled_in' => '08:00', 'scheduled_out' => '16:00', 'in_time' => '08:00', 'out_time' => '16:00', 'outside_amount' => '75.5', 'transport' => 'half', 'advance_amount' => '200', 'note' => 'n']));
    }

    public function test_a_leave_day_rejects_times_minutes_outside_and_transport_but_allows_an_advance(): void
    {
        $this->assertSame([
            ['field' => 'scheduled_in', 'code' => 'field_not_allowed'],
            ['field' => 'in_time', 'code' => 'field_not_allowed'],
            ['field' => 'transport', 'code' => 'field_not_allowed'],
        ], DayRules::violations(EmployeeType::PermanentWorker, self::LEAVE, ['scheduled_in' => '08:00', 'in_time' => '08:00', 'transport' => 'full']));
        $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::LEAVE, ['transport' => 'none', 'advance_amount' => '200', 'note' => 'n']));
        $this->assertSame([['field' => 'ot_minutes', 'code' => 'field_not_allowed']], DayRules::violations(EmployeeType::DayLaborer, self::LEAVE, ['ot_minutes' => 30]));
        $this->assertSame([], DayRules::violations(EmployeeType::Engineer, self::LEAVE, ['advance_amount' => '500']));
    }

    public function test_bounds(): void
    {
        $this->assertSame([], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['late_minutes' => 960, 'ot_minutes' => 0]));
        $this->assertSame([['field' => 'late_minutes', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['late_minutes' => 961]));
        $this->assertSame([['field' => 'ot_minutes', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['ot_minutes' => -1]));
        $this->assertSame([['field' => 'advance_amount', 'code' => 'out_of_bounds']], DayRules::violations(EmployeeType::DayLaborer, self::WORK, ['advance_amount' => '0']));
        $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['outside_amount' => '0.000001']));
    }

    public function test_times_order(): void
    {
        $this->assertSame([['field' => 'out_time', 'code' => 'out_before_in']], DayRules::violations(EmployeeType::Engineer, self::WORK, ['in_time' => '20:00', 'out_time' => '04:00']));
        $this->assertSame([['field' => 'out_time', 'code' => 'times_need_both']], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['in_time' => '08:00']));
        $this->assertSame([], DayRules::violations(EmployeeType::PermanentWorker, self::WORK, ['in_time' => '20:00', 'out_time' => '04:00']));
    }

    public function test_allows_matches_the_field_rules(): void
    {
        $inside = [...self::WORK, 'outside_alexandria' => false];
        $this->assertFalse(DayRules::allows(EmployeeType::Engineer, $inside, 'outside_minutes', 30));
        $this->assertTrue(DayRules::allows(EmployeeType::Engineer, self::WORK, 'outside_minutes', 30));
        $this->assertTrue(DayRules::allows(EmployeeType::PermanentWorker, self::LEAVE, 'transport', 'none'));
        $this->assertFalse(DayRules::allows(EmployeeType::PermanentWorker, self::LEAVE, 'transport', 'full'));
        $this->assertFalse(DayRules::allows(EmployeeType::Office, null, 'note', 'n'));
        $this->assertTrue(DayRules::allows(EmployeeType::Office, null, 'in_time', '09:00'));
    }

    public function test_computed_minutes(): void
    {
        $this->assertSame(['late' => 30, 'ot' => 30], DayRules::workerMinutes('20:00', '20:30', '05:00', '8'));
        $this->assertSame(['late' => 0, 'ot' => 10], DayRules::workerMinutes('08:00', '07:50', '16:00', '8'));
        $this->assertSame(['late' => 15, 'ot' => 0], DayRules::workerMinutes('08:00', '08:15', '15:00', '8'));
        $this->assertSame(['late' => 0, 'ot' => 30], DayRules::workerMinutes('08:00', '08:00', '16:00', '7.5'));
        $this->assertSame(25, DayRules::officeLateMinutes('09:40', '09:00', 15));
        $this->assertSame(0, DayRules::officeLateMinutes('09:15', '09:00', 15));
        $this->assertSame(0, DayRules::officeLateMinutes('08:50', '09:00', 15));
    }

    public function test_off_days_and_employment(): void
    {
        $this->assertSame('friday', OffDays::leaveCodeFor('2026-10-02', ['friday'], false));
        $this->assertSame('saturday', OffDays::leaveCodeFor('2026-10-03', ['friday', 'saturday'], false));
        $this->assertSame('thursday', OffDays::leaveCodeFor('2026-10-01', ['thursday'], false));
        $this->assertNull(OffDays::leaveCodeFor('2026-10-03', ['friday'], false));
        $this->assertSame('official_holiday', OffDays::leaveCodeFor('2026-10-09', ['friday'], true));
        $e = ['hire_date' => '2026-10-10', 'termination_date' => '2026-10-20', 'types' => [['type' => 'day_laborer', 'effective_from' => '2026-10-10'], ['type' => 'engineer', 'effective_from' => '2026-10-16']], 'shifts' => []];
        $this->assertNull(Employment::typeOn($e, '2026-10-09'));
        $this->assertSame(EmployeeType::DayLaborer, Employment::typeOn($e, '2026-10-15'));
        $this->assertSame(EmployeeType::Engineer, Employment::typeOn($e, '2026-10-20'));
        $this->assertNull(Employment::typeOn($e, '2026-10-21'));
    }

    public function test_the_shift_in_force_on_a_date(): void
    {
        $e = ['hire_date' => null, 'termination_date' => null, 'types' => [], 'shifts' => [['effective_from' => '2026-10-01', 'shift_start' => '10:00', 'shift_end' => '18:00'], ['effective_from' => '2026-10-16', 'shift_start' => null, 'shift_end' => null]]];
        $this->assertSame(['start' => null, 'end' => null], Employment::shiftOn($e, '2026-09-30'));
        $this->assertSame(['start' => '10:00', 'end' => '18:00'], Employment::shiftOn($e, '2026-10-15'));
        $this->assertSame(['start' => null, 'end' => null], Employment::shiftOn($e, '2026-10-16'));
        $this->assertNull(Employment::typeOn($e, '2026-10-15'));
    }
}
