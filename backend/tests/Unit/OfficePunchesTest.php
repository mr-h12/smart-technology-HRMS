<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\OfficePunches;
use App\Modules\Attendance\Domain\PunchedDay;
use App\Modules\Attendance\Domain\PunchFlag;
use PHPUnit\Framework\TestCase;

/** The cases of issue #68 (FP-3), shift 09:00 to 17:00 unless a case names another. */
class OfficePunchesTest extends TestCase
{
    public function test_one_early_punch_is_an_in_time(): void
    {
        $this->assertDay(['08:53:25'], '08:53', null, [PunchFlag::MissingPunch], 1);
    }

    public function test_one_late_punch_is_an_out_time(): void
    {
        $this->assertDay(['17:05:00'], null, '17:05', [PunchFlag::MissingPunch], 1);
    }

    public function test_one_punch_before_the_shift_end_is_an_early_out_time(): void
    {
        $this->assertDay(['16:30:10'], null, '16:30', [PunchFlag::MissingPunch, PunchFlag::EarlyLeave], 1);
    }

    public function test_one_punch_halfway_is_an_out_time(): void
    {
        $this->assertDay(['13:00:00'], null, '13:00', [PunchFlag::MissingPunch, PunchFlag::EarlyLeave], 1);
    }

    public function test_an_arrival_double_tap_is_one_in_time(): void
    {
        $this->assertDay(['08:53:25', '08:53:26'], '08:53', null, [PunchFlag::MissingPunch], 2);
    }

    public function test_a_departure_double_tap_takes_the_latest_time(): void
    {
        $this->assertDay(['16:59:58', '17:00:01'], null, '17:00', [PunchFlag::MissingPunch], 2);
    }

    public function test_a_double_tap_across_the_shift_start_takes_the_earliest_time(): void
    {
        $this->assertDay(['08:59:59', '09:00:01'], '08:59', null, [PunchFlag::MissingPunch], 2);
    }

    public function test_punches_119_seconds_apart_are_a_double_tap(): void
    {
        $this->assertDay(['08:50:00', '08:51:59'], '08:50', null, [PunchFlag::MissingPunch], 2);
    }

    public function test_punches_120_seconds_apart_are_an_arrival_and_a_departure(): void
    {
        $this->assertDay(['08:50:00', '08:52:00'], '08:50', '08:52', [PunchFlag::EarlyLeave], 2);
    }

    public function test_the_double_tap_window_is_measured_in_seconds(): void
    {
        $this->assertDay(['08:50:59', '08:52:00'], '08:50', null, [PunchFlag::MissingPunch], 2);
    }

    public function test_double_taps_at_both_ends_give_a_full_day(): void
    {
        $this->assertDay(['08:51:58', '08:51:59', '17:27:01', '17:27:02'], '08:51', '17:27', [], 4);
    }

    public function test_middle_punches_are_ignored(): void
    {
        $this->assertDay(['08:52:04', '15:59:04', '16:00:08'], '08:52', '16:00', [PunchFlag::EarlyLeave], 3);
    }

    public function test_unsorted_punches_are_sorted(): void
    {
        $this->assertDay(['17:30:00', '08:59:59'], '08:59', '17:30', [], 2);
    }

    public function test_punches_exactly_on_the_shift_give_no_flag(): void
    {
        $this->assertDay(['17:00:00', '09:00:00'], '09:00', '17:00', [], 2);
    }

    public function test_one_punch_is_classified_against_the_employees_own_shift(): void
    {
        $this->assertDay(['09:00:00'], '09:00', null, [PunchFlag::MissingPunch], 1, '10:00', '18:00');
    }

    public function test_one_punch_distance_uses_the_truncated_minute(): void
    {
        $this->assertDay(['13:00:45'], '13:00', null, [PunchFlag::MissingPunch], 1, '09:00', '17:01');
    }

    /**
     * @param  non-empty-list<string>  $times
     * @param  list<PunchFlag>  $flags
     */
    private function assertDay(array $times, ?string $in, ?string $out, array $flags, int $count, string $start = '09:00', string $end = '17:00'): void
    {
        $this->assertEquals(new PunchedDay($in, $out, $flags, $count), OfficePunches::reduce($times, $start, $end));
    }
}
