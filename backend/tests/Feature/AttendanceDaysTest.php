<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceDaysTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-1');
        $this->outside = $this->workSite('Site-2', true);
    }

    public function test_each_type_saves_its_own_fields(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->outside, 'in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => 10, 'ot_minutes' => 60, 'outside_minutes' => 30, 'advance_amount' => '500', 'note' => 'n'])
            ->assertOk()->assertJsonPath('data.state', 'stored')->assertJsonPath('data.source', 'entered')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.advance_amount', '500.000000')->assertJsonPath('data.in_time', '08:00');
        $this->putDay($this->employee('day_laborer'), '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:00'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'in_time');
        $this->putDay($this->employee('office'), '2026-10-01', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
    }

    public function test_a_leave_day_stores_no_transport_and_keeps_the_advance(): void
    {
        $w = $this->employee('permanent_worker');
        $this->putDay($w, '2026-10-02', ['site_id' => $this->leave['friday'], 'transport' => 'half'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'transport');
        $this->putDay($w, '2026-10-02', ['site_id' => $this->leave['friday'], 'advance_amount' => '200'])
            ->assertOk()->assertJsonPath('data.transport', 'none')->assertJsonPath('data.scheduled_in', null)->assertJsonPath('data.leave_code', 'friday');
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->putDay($this->employee('engineer'), '2026-10-21', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.code', 'future_date');
    }

    public function test_today_follows_the_cairo_date(): void
    {
        $eng = $this->employee('engineer');
        $this->cairo('2026-10-21 00:30');                       // UTC is still 2026-10-20
        $this->putDay($eng, '2026-10-21', ['site_id' => $this->site])->assertOk();
        $this->putDay($eng, '2026-10-22', ['site_id' => $this->site])->assertJsonPath('error.code', 'future_date');
    }

    public function test_an_employee_not_active_on_the_date_is_refused(): void
    {
        $late = $this->employee('engineer', '2026-10-10');
        $this->putDay($late, '2026-10-05', ['site_id' => $this->site])->assertStatus(422)->assertJsonPath('error.code', 'employee_not_active');
        $left = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-12']);
        $this->putDay($left, '2026-10-12', ['site_id' => $this->site])->assertOk();
        $this->putDay($left, '2026-10-13', ['site_id' => $this->site])->assertJsonPath('error.code', 'employee_not_active');
    }

    public function test_a_night_shift_crosses_midnight_and_belongs_to_the_in_date(): void
    {
        $this->putDay($this->employee('permanent_worker'), '2026-10-01', ['site_id' => $this->site, 'scheduled_in' => '20:00', 'scheduled_out' => '04:00', 'in_time' => '20:30', 'out_time' => '05:00', 'transport' => 'full'])
            ->assertOk()->assertJsonPath('data.date', '2026-10-01')->assertJsonPath('data.hints.late_minutes', 30)->assertJsonPath('data.hints.ot_minutes', 30);
        $this->putDay($this->employee('engineer'), '2026-10-01', ['site_id' => $this->site, 'in_time' => '20:00', 'out_time' => '04:00'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'out_time');
    }

    public function test_scheduled_times_default_from_the_pay_row_or_the_settings(): void
    {
        $this->putDay($this->employee('permanent_worker'), '2026-10-01', ['site_id' => $this->site])->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.scheduled_out', '16:00')
            ->assertJsonPath('data.transport', 'full');
        $own = $this->employee('permanent_worker', '2026-01-01', ['shift_start' => '07:00', 'shift_end' => '15:00']);
        $this->getJson("/api/v1/attendance/days/{$own}/2026-10-01")->assertJsonPath('data.state', 'missing')->assertJsonPath('data.scheduled_in', '07:00')
            ->assertJsonPath('data.transport', 'full');
    }

    public function test_office_lateness_hint_uses_the_shift_and_the_grace(): void
    {
        $o = $this->employee('office');
        $this->putDay($o, '2026-10-01', ['in_time' => '09:40', 'out_time' => '17:00'])->assertJsonPath('data.hints.late_minutes', 25);
        $this->putDay($o, '2026-10-04', ['in_time' => '09:10'])->assertJsonPath('data.hints.late_minutes', 0);
        $later = $this->employee('office', '2026-01-01', ['shift_start' => '10:00', 'shift_end' => '18:00']);
        $this->putDay($later, '2026-10-01', ['in_time' => '10:20'])->assertJsonPath('data.hints.late_minutes', 5);
    }

    public function test_a_stale_version_returns_409_concurrency_conflict(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertJsonPath('data.version', 1);
        $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site, 'late_minutes' => 20])->assertJsonPath('data.version', 2);
        $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertStatus(409);
        $this->assertSame(20, AttendanceDay::where('employee_id', $eng)->value('late_minutes'));
    }

    public function test_clearing_a_day_soft_deletes_it_and_the_day_is_missing_again(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site]);
        $id = AttendanceDay::where('employee_id', $eng)->value('id');
        $this->deleteJson("/api/v1/attendance/days/{$eng}/2026-10-01?version=1")->assertOk()->assertJsonPath('data.state', 'missing');
        $this->assertSoftDeleted('attendance_days', ['id' => $id]);
        $this->putDay($eng, '2026-10-01', ['version' => null, 'site_id' => $this->site])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame(1, AttendanceDay::withTrashed()->where('employee_id', $eng)->count());
    }

    public function test_fridays_and_holidays_are_derived_and_a_holiday_wins_over_friday(): void
    {
        $this->holiday('2026-10-06');
        $this->holiday('2026-10-09');
        $eng = $this->employee('engineer');
        $day = fn (string $e, string $d) => $this->getJson("/api/v1/attendance/days/{$e}/{$d}");
        $day($eng, '2026-10-02')->assertJsonPath('data.state', 'derived')->assertJsonPath('data.leave_code', 'friday')
            ->assertJsonPath('data.site_id', $this->leave['friday'])->assertJsonPath('data.version', null);
        $day($eng, '2026-10-06')->assertJsonPath('data.leave_code', 'official_holiday');
        $day($eng, '2026-10-09')->assertJsonPath('data.leave_code', 'official_holiday');   // Friday and holiday
        $day($eng, '2026-10-03')->assertJsonPath('data.state', 'missing');               // Saturday, field staff
        $day($this->employee('office'), '2026-10-03')->assertJsonPath('data.leave_code', 'saturday')   // office weekly off
            ->assertJsonPath('data.site_id', $this->leave['saturday']);                                  // «اجازة السبت»
        $day($this->employee('permanent_worker'), '2026-10-02')->assertJsonPath('data.state', 'derived')   // a leave day: no work-day defaults
            ->assertJsonPath('data.scheduled_in', null)->assertJsonPath('data.scheduled_out', null)->assertJsonPath('data.transport', 'none');
        $this->putDay($eng, '2026-10-02', ['site_id' => $this->site])->assertJsonPath('data.state', 'stored');   // worked the Friday
    }

    public function test_missing_days_are_counted_and_never_treated_as_absence(): void
    {
        $this->holiday('2026-10-06');
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site]);
        $this->putDay($eng, '2026-10-03', ['site_id' => $this->leave['absent']]);
        $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-09-26')->assertJsonPath('data.missing', 19);
        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-04")->assertJsonPath('data.state', 'missing')->assertJsonPath('data.leave_code', null);
        $states = array_count_values(array_column($this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26')->json('data.0.days'), 'state'));
        ksort($states);
        $this->assertSame(['derived' => 5, 'future' => 4, 'missing' => 19, 'stored' => 2], $states); // Friday 10-23 is derived ahead of time
        $this->assertSame(2, AttendanceDay::count());                                                // a missing day is never stored as absent
    }

    public function test_days_outside_employment_are_not_missing(): void
    {
        $this->employee('engineer', '2026-10-10');
        $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-09-26')->assertJsonPath('data.missing', 10); // 10-10..10-20 less Friday 10-16
        $days = $this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26')->json('data.0.days');
        $this->assertSame('not_employed', $days[0]['state']);   // 2026-09-26
        $this->assertSame('not_employed', $days[13]['state']);  // 2026-10-09
    }

    public function test_grid_lists_the_groups_employees_by_code_with_one_cell_per_day(): void
    {
        $a = $this->employee('engineer');
        $b = $this->employee('engineer');
        $this->employee('office');
        $r = $this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26&per_page=50')->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('data.0.employee.id', $a)->assertJsonPath('data.1.employee.id', $b);
        $this->assertCount(30, $r->json('data.0.days'));
    }

    public function test_period_navigation(): void
    {
        $this->getJson('/api/v1/attendance/period?group=engineer')->assertJsonPath('data.start', '2026-09-26')->assertJsonPath('data.end', '2026-10-25')
            ->assertJsonPath('data.prev_start', '2026-08-26')->assertJsonPath('data.next_start', null)->assertJsonPath('data.is_locked', false);
        $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-08-26')->assertJsonPath('data.next_start', '2026-09-26');
        $this->getJson('/api/v1/attendance/period?group=engineer&period_start=2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'period_start');
    }

    public function test_ceo_reads_and_a_manager_is_refused(): void
    {
        $eng = $this->employee('engineer');
        $this->signIn('ceo');
        $this->getJson('/api/v1/attendance/grid?group=engineer')->assertOk();
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site])->assertStatus(403);
        $this->signIn('manager');
        $this->getJson('/api/v1/attendance/grid?group=engineer')->assertStatus(403);
        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-01")->assertStatus(403);
    }

    public function test_every_write_is_audited_with_before_and_after_values(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site, 'late_minutes' => 10]);
        $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site, 'late_minutes' => 20]);
        $this->deleteJson("/api/v1/attendance/days/{$eng}/2026-10-01?version=2");
        $rows = DB::table('audit_log')->where('entity_type', 'attendance_days')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED', 'UPDATED', 'DELETED'], $rows->pluck('event')->all()); // the clear bumps the version, then soft-deletes
        $this->assertEquals(['late_minutes' => 10, 'version' => 1], json_decode($rows[1]->old_values, true));
        $this->assertEquals(['late_minutes' => 20, 'version' => 2], json_decode($rows[1]->new_values, true));
    }
}
