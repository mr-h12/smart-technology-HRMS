<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Employees\Application\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** One employee's days in their own payroll period, with its day counts (owner, 2026-11-10). */
class EmployeeAttendancePeriodTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-1');
    }

    private function period(string $employeeId, ?string $date = null): TestResponse
    {
        return $this->getJson("/api/v1/attendance/employees/{$employeeId}/period".($date === null ? '' : "?date={$date}"));
    }

    public function test_each_type_reads_its_own_period_holding_today_by_default(): void
    {
        $periods = [
            'office' => ['2026-10-01', '2026-10-31', '2026-09-30', 31],
            'engineer' => ['2026-09-26', '2026-10-25', '2026-09-25', 30],
            'permanent_worker' => ['2026-09-26', '2026-10-25', '2026-09-25', 30],
            'day_laborer' => ['2026-10-16', '2026-10-31', '2026-10-15', 16],
        ];
        foreach ($periods as $type => [$start, $end, $prev, $days]) {
            $this->period($this->employee($type))->assertOk()
                ->assertJsonPath('data.period', ['group' => $type, 'start' => $start, 'end' => $end, 'prev_date' => $prev, 'next_date' => null, 'is_locked' => false])
                ->assertJsonPath('data.fields', DayRules::FIELDS[$type])
                ->assertJsonCount($days, 'data.days')->assertJsonPath('data.days.0.date', $start);
        }
    }

    public function test_a_date_picks_its_period_and_a_locked_one_says_so(): void
    {
        $eng = $this->employee('engineer');
        $this->period($eng, '2026-08-10')->assertJsonPath('data.period.start', '2026-07-26')->assertJsonPath('data.period.next_date', '2026-08-26');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-08-26', 'confirm_missing' => true])->assertCreated();
        $this->period($eng, '2026-09-01')->assertJsonPath('data.period.is_locked', true);
        $this->period($eng, '2026-02-30')->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
        $this->period('01a1c572-b8b2-710c-81cc-fa20bc88fbfb')->assertNotFound();
    }

    public function test_a_type_change_reads_each_type_in_its_own_period_and_the_arrows_cross_it_without_a_gap(): void
    {
        $e = $this->employee('day_laborer');
        app(EmployeeService::class)->addTypeChange($e, ['type' => 'engineer', 'effective_from' => '2026-09-26']);

        $this->period($e, '2026-10-01')->assertJsonPath('data.period.group', 'engineer')->assertJsonPath('data.period.start', '2026-09-26')
            ->assertJsonPath('data.period.prev_date', '2026-09-25');
        $before = $this->period($e, '2026-09-25')->assertJsonPath('data.period.group', 'day_laborer')
            ->assertJsonPath('data.period.start', '2026-09-16')->assertJsonPath('data.period.end', '2026-09-30')
            ->assertJsonPath('data.period.next_date', '2026-09-26')->assertJsonPath('data.fields', ['in_time', 'out_time', 'ot_minutes', 'late_minutes', 'advance_amount', 'note']);
        $state = array_column($before->json('data.days'), 'state', 'date');
        $this->assertSame(['missing', 'derived', 'not_employed'], [$state['2026-09-24'], $state['2026-09-25'], $state['2026-09-26']]);   // Friday 09-25; an engineer from 09-26
    }

    public function test_a_date_outside_the_employment_is_clamped_to_it(): void
    {
        $left = $this->employee('engineer', '2026-03-05', ['termination_date' => '2026-08-10']);
        $r = $this->period($left)->assertJsonPath('data.period.start', '2026-07-26')->assertJsonPath('data.period.next_date', null);
        $this->assertSame('not_employed', array_column($r->json('data.days'), 'state', 'date')['2026-08-11']);
        $r = $this->period($left, '2025-06-01')->assertJsonPath('data.period.start', '2026-02-26')->assertJsonPath('data.period.prev_date', null);
        $this->assertSame('not_employed', array_column($r->json('data.days'), 'state', 'date')['2026-03-04']);
    }

    public function test_the_summary_counts_the_days_up_to_today_and_each_day_carries_its_values_and_hints(): void
    {
        $this->holiday('2026-10-06');
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:45', 'out_time' => '18:30'])->assertOk();
        $this->putDay($eng, '2026-10-04', ['site_id' => $this->site])->assertOk();
        $this->putDay($eng, '2026-10-09', ['site_id' => $this->site])->assertOk();          // worked a Friday
        $this->putDay($eng, '2026-10-03', ['site_id' => $this->leave['absent']])->assertOk();
        $this->putDay($eng, '2026-10-05', ['site_id' => $this->leave['sick']])->assertOk();
        $this->putDay($eng, '2026-10-07', ['site_id' => $this->leave['annual']])->assertOk();
        $this->putDay($eng, '2026-10-08', ['site_id' => $this->leave['rest']])->assertOk();
        app(AttendanceDays::class)->writeLeaveDay($eng, '2026-10-22', $this->leave['annual']);   // stored, but after today

        $r = $this->period($eng)->assertOk()
            // Fridays 10-02 and 10-16 and the holiday 10-06 are derived; 15 of the 25 days up to today are missing; 10-21..10-25 count nowhere
            ->assertJsonPath('data.summary', ['worked' => 3, 'absent' => 1, 'leave' => 2, 'off' => 4, 'missing' => 15]);
        $day = array_column($r->json('data.days'), null, 'date');
        $this->assertSame(['stored', $this->site, '08:45', '18:30', 1, ['late_minutes' => 45, 'ot_minutes' => 105]],
            [$day['2026-10-01']['state'], $day['2026-10-01']['site_id'], $day['2026-10-01']['in_time'], $day['2026-10-01']['out_time'], $day['2026-10-01']['version'], $day['2026-10-01']['hints']]);
        $this->assertSame(['derived', 'friday', []], [$day['2026-10-02']['state'], $day['2026-10-02']['leave_code'], $day['2026-10-02']['hints']]);
        $this->assertSame(['stored', 'annual'], [$day['2026-10-22']['state'], $day['2026-10-22']['leave_code']]);
        $this->assertSame('future', $day['2026-10-21']['state']);
    }

    public function test_it_needs_attendance_view(): void
    {
        $eng = $this->employee('engineer');
        $this->signIn('manager');
        $this->period($eng)->assertForbidden();
    }

    public function test_an_own_scope_reader_reaches_only_the_employees_in_their_reach(): void
    {
        $lead = $this->userWith('own', null);
        DB::table('manager_sites')->insert(['manager_id' => $lead->id, 'site_id' => $this->site]);
        $mine = $this->employee('permanent_worker');
        $this->homeSite($mine, $this->site, '2026-09-01');
        $other = $this->employee('permanent_worker');
        $this->homeSite($other, $this->workSite('Site-2'), '2026-09-01');

        $this->actingAs($lead, 'api');
        $this->period($mine)->assertOk();
        $this->period($other)->assertNotFound();
    }
}
