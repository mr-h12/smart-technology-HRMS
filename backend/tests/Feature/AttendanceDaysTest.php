<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->putDay($this->employee('day_laborer'), '2026-10-01', ['site_id' => $this->site, 'scheduled_in' => '08:00'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'scheduled_in');
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
        $this->putDay($this->employee('permanent_worker'), '2026-10-01', ['site_id' => $this->site])->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.scheduled_out', '17:00')
            ->assertJsonPath('data.transport', 'full');
        $own = $this->employee('permanent_worker', '2026-01-01', ['shift_start' => '07:00', 'shift_end' => '15:00']);
        $this->getJson("/api/v1/attendance/days/{$own}/2026-10-01")->assertJsonPath('data.state', 'missing')->assertJsonPath('data.scheduled_in', '07:00')
            ->assertJsonPath('data.transport', 'full');
    }

    public function test_single_day_entry_fills_no_times(): void
    {
        $this->putDay($this->employee('engineer'), '2026-10-01', ['site_id' => $this->site])->assertOk()
            ->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);
    }

    public function test_an_engineers_times_give_hints_and_leave_the_stored_minutes_alone(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:45', 'out_time' => '18:30', 'late_minutes' => 5, 'ot_minutes' => 0])->assertOk()
            ->assertJsonPath('data.hints.late_minutes', 45)->assertJsonPath('data.hints.ot_minutes', 105)
            ->assertJsonPath('data.late_minutes', 5)->assertJsonPath('data.ot_minutes', 0);
        $this->putDay($eng, '2026-10-04', ['site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00'])->assertOk()
            ->assertJsonPath('data.hints.late_minutes', 0)->assertJsonPath('data.hints.ot_minutes', 60)
            ->assertJsonPath('data.late_minutes', null)->assertJsonPath('data.ot_minutes', null);
    }

    public function test_a_day_laborer_stores_times_in_order_and_gets_hints(): void
    {
        $l = $this->employee('day_laborer');
        $this->getJson("/api/v1/attendance/days/{$l}/2026-10-01")->assertJsonPath('data.fields', ['in_time', 'out_time', 'ot_minutes', 'late_minutes', 'advance_amount', 'note']);
        $this->putDay($l, '2026-10-01', ['site_id' => $this->site, 'in_time' => '17:00', 'out_time' => '08:00'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'out_time')->assertJsonPath('error.details.0.message', __('attendance.rule.out_before_in'));
        $this->putDay($l, '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:45', 'out_time' => '17:00'])->assertOk()
            ->assertJsonPath('data.in_time', '08:45')->assertJsonPath('data.hints.late_minutes', 45)->assertJsonPath('data.hints.ot_minutes', 15)
            ->assertJsonPath('data.late_minutes', null);
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

    public function test_a_stale_put_or_clear_answers_409_with_its_message_and_no_details(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site])->assertOk();

        foreach (self::CONFLICT_MESSAGE as $locale => $message) {
            $error = ['code' => 'concurrency_conflict', 'message' => $message];
            $this->withHeader('Accept-Language', $locale);
            $this->putDay($eng, '2026-10-01', ['version' => 7, 'site_id' => $this->site])->assertStatus(409)->assertJsonPath('error', $error);
            $this->deleteJson("/api/v1/attendance/days/{$eng}/2026-10-01?version=7")->assertStatus(409)->assertJsonPath('error', $error);
        }
    }

    public function test_a_racing_insert_on_put_answers_409_with_no_details(): void
    {
        $eng = $this->employee('engineer');
        // Another request inserts the day between this request's read and its insert.
        AttendanceDay::creating(function () use ($eng): void {
            DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $eng, 'date' => '2026-10-01', 'source' => 'entered']);
        });

        $this->withHeader('Accept-Language', 'ar')->putDay($eng, '2026-10-01', ['site_id' => $this->site])
            ->assertStatus(409)->assertJsonPath('error', ['code' => 'concurrency_conflict', 'message' => self::CONFLICT_MESSAGE['ar']]);
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

    public function test_grid_site_filter_lists_only_who_worked_at_the_site_in_the_period(): void
    {
        $a = $this->employee('engineer');
        $b = $this->employee('engineer');
        $c = $this->employee('engineer');
        $this->putDay($a, '2026-10-01', ['site_id' => $this->site]);
        $this->putDay($b, '2026-10-01', ['site_id' => $this->workSite('Site-3')]);
        $this->putDay($c, '2026-10-01', ['site_id' => $this->site]);
        $this->deleteJson("/api/v1/attendance/days/{$c}/2026-10-01?version=1")->assertOk();   // a cleared day does not count
        $this->getJson("/api/v1/attendance/grid?group=engineer&period_start=2026-09-26&site_id={$this->site}")->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $a);
        $this->getJson("/api/v1/attendance/grid?group=engineer&period_start=2026-08-26&site_id={$this->site}")
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);                                    // the day is outside that period
        $this->getJson('/api/v1/attendance/grid?group=engineer&site_id=nope')->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
    }

    public function test_grid_search_matches_a_name_part_in_either_language_or_the_exact_code(): void
    {
        $a = $this->employee('engineer', '2026-01-01', ['name_ar' => 'محمود سامي', 'name_en' => 'Mahmoud Sami']);
        $b = $this->employee('engineer');
        $this->employee('office', '2026-01-01', ['name_ar' => 'سامي علي']);   // another group
        $code = (int) DB::table('employees')->where('id', $b)->value('code');
        $ids = fn (string $search) => array_column($this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26&search='.urlencode($search))->assertOk()
            ->json('data.*.employee'), 'id');

        $this->assertSame([$a], $ids('سامي'));
        $this->assertSame([$a], $ids('SAMI'));
        $this->assertSame([$b], $ids((string) $code));
        $this->assertSame([$a, $b], $ids(''));                                                        // blank is no filter
        $this->getJson('/api/v1/attendance/grid?group=engineer&search=nobody')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v1/attendance/grid?group=engineer&search='.str_repeat('x', 101))->assertStatus(422)->assertJsonPath('error.details.0.field', 'search');
    }

    public function test_grid_search_combines_with_the_site_and_counts_only_the_matches(): void
    {
        $a = $this->employee('engineer');
        $this->employee('engineer');
        $c = $this->employee('engineer', '2026-01-01', ['name_ar' => 'Engineer-Site']);
        $this->employee('engineer', '2026-01-01', ['name_ar' => 'Engineer-Away']);
        $this->putDay($a, '2026-10-01', ['site_id' => $this->site]);
        $this->putDay($c, '2026-10-01', ['site_id' => $this->site]);
        $this->getJson("/api/v1/attendance/grid?group=engineer&period_start=2026-09-26&site_id={$this->site}&search=site")->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $c);
        $this->getJson('/api/v1/attendance/grid?group=all&period_start=2026-09-26&per_page=1&search=engineer-')->assertOk()
            ->assertJsonPath('meta.pagination.total', 4)->assertJsonPath('meta.pagination.total_pages', 4);
    }

    public function test_grid_search_stays_inside_an_own_scope_readers_reach(): void
    {
        $s2 = $this->workSite('Site-3');
        $lead = $this->userWith('own', null);
        DB::table('manager_sites')->insert(['manager_id' => $lead->id, 'site_id' => $this->site]);
        $mine = $this->employee('permanent_worker');
        $this->homeSite($mine, $this->site, '2026-09-01');
        $this->homeSite($this->employee('permanent_worker', '2026-01-01', ['name_ar' => 'Other']), $this->site, '2026-09-01');   // reached, no match
        $this->homeSite($this->employee('permanent_worker'), $s2, '2026-09-01');                                                 // a match out of reach

        $this->actingAs($lead, 'api');
        $this->getJson('/api/v1/attendance/grid?group=permanent_worker&period_start=2026-09-26&search=worker')->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.employee.id', $mine);
    }

    public function test_grid_all_with_a_site_lists_every_field_type_that_worked_there_in_the_engineer_window(): void
    {
        $eng = $this->employee('engineer');
        $lab = $this->employee('day_laborer');
        $other = $this->employee('day_laborer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->site]);
        $this->putDay($lab, '2026-10-18', ['site_id' => $this->site]);
        $this->putDay($other, '2026-10-01', ['site_id' => $this->workSite('Site-3')]);
        $r = $this->getJson("/api/v1/attendance/grid?group=all&period_start=2026-09-26&site_id={$this->site}")->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('data.0.employee.id', $eng)->assertJsonPath('data.1.employee.id', $lab);
        $this->assertCount(30, $r->json('data.1.days'));                                               // 2026-09-26..2026-10-25
        $this->assertSame('stored', $r->json('data.1.days.22.state'));                                 // 2026-10-18
        $this->assertSame('missing', $r->json('data.1.days.0.state'));                                 // 2026-09-26, in the laborers' 16–30 half-month
    }

    public function test_grid_all_without_a_site_lists_every_type(): void
    {
        $eng = $this->employee('engineer');
        $worker = $this->employee('permanent_worker');
        $lab = $this->employee('day_laborer');
        $office = $this->employee('office');
        $ids = array_column($this->getJson('/api/v1/attendance/grid?group=all&period_start=2026-09-26&per_page=50')->assertOk()
            ->assertJsonPath('meta.pagination.total', 4)->json('data.*.employee'), 'id');
        $this->assertSame([$eng, $worker, $lab, $office], $ids);
    }

    public function test_grid_all_keeps_a_day_laborers_lock_per_half_month(): void
    {
        $eng = $this->employee('engineer');
        $lab = $this->employee('day_laborer');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-10-01', 'confirm_missing' => true])->assertCreated();
        app(BusinessRules::class)->update(['weekly_off_field' => 'friday,saturday']);   // after the lock: only an open period derives it
        $data = $this->getJson('/api/v1/attendance/grid?group=all&period_start=2026-09-26')->assertOk()->json('data');
        $byId = array_column(array_map(fn (array $r) => ['id' => $r['employee']['id'], 'days' => $r['days']], $data), 'days', 'id');
        $this->assertSame('derived', $byId[$eng][7]['state']);    // Saturday 2026-10-03, the engineer period is open
        $this->assertSame('missing', $byId[$lab][7]['state']);    // the same Saturday in the laborers' locked 1–15
        $this->assertSame('derived', $byId[$lab][21]['state']);   // Saturday 2026-10-17, the open 16–31
    }

    public function test_group_all_has_no_period_or_missing_days_of_its_own(): void
    {
        $this->getJson('/api/v1/attendance/period?group=all')->assertStatus(422)->assertJsonPath('error.details.0.field', 'group');
        $this->getJson('/api/v1/attendance/missing?group=all')->assertStatus(422);
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

    public function test_a_leave_day_may_be_stored_in_the_future_while_entry_still_refuses_it(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-21', ['site_id' => $this->leave['annual']])->assertStatus(422)->assertJsonPath('error.code', 'future_date');

        app(AttendanceDays::class)->writeLeaveDay($eng, '2026-10-21', $this->leave['annual']);

        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-21")->assertOk()
            ->assertJsonPath('data.leave_code', 'annual')->assertJsonPath('data.source', 'leave')->assertJsonPath('data.version', 1);
    }

    public function test_a_leave_day_replaces_a_stored_leave_day_but_never_a_work_day_or_office_present(): void
    {
        $eng = $this->employee('engineer');
        $office = $this->employee('office');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->leave['sick'], 'note' => 'n', 'advance_amount' => '200'])->assertOk();
        $this->putDay($eng, '2026-10-04', ['site_id' => $this->site])->assertOk();
        $this->putDay($office, '2026-10-01', ['site_id' => null, 'in_time' => '09:00'])->assertOk();
        $days = app(AttendanceDays::class);

        $days->writeLeaveDay($eng, '2026-10-01', $this->leave['annual'], true);   // HR ticked replace (A3)
        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-01")
            ->assertJsonPath('data.leave_code', 'annual')->assertJsonPath('data.source', 'leave')->assertJsonPath('data.note', 'n')
            ->assertJsonPath('data.advance_amount', '200.000000')->assertJsonPath('data.version', 2);

        foreach ([[$eng, '2026-10-04'], [$office, '2026-10-01']] as [$employee, $date]) {
            try {
                $days->writeLeaveDay($employee, $date, $this->leave['annual']);
                $this->fail("{$date} was overwritten");
            } catch (AttendanceRefused $e) {
                $this->assertSame(AttendanceRefusal::LeaveDaysBlocked, $e->reason);
            }
            $this->assertSame(1, AttendanceDay::where(['employee_id' => $employee, 'date' => $date])->value('version'));
        }
        $this->assertSame(['2026-10-04'], $days->leaveBlockedDates($eng, ['2026-10-01', '2026-10-04', '2026-10-05']));
        $this->assertSame(['2026-10-01'], $days->leaveBlockedDates($office, ['2026-10-01']));
    }

    public function test_a_locked_period_blocks_a_leave_day(): void
    {
        $eng = $this->employee('engineer', '2026-09-26');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();
        $days = app(AttendanceDays::class);

        $this->assertSame(['2026-10-12'], $days->leaveBlockedDates($eng, ['2026-10-12', '2026-10-26']));
        $this->expectExceptionObject(AttendanceRefused::because(AttendanceRefusal::PeriodLocked));
        $days->writeLeaveDay($eng, '2026-10-12', $this->leave['annual']);
    }

    public function test_re_saving_a_leave_written_day_keeps_its_source_until_its_site_changes(): void
    {
        $eng = $this->employee('engineer');
        app(AttendanceDays::class)->writeLeaveDay($eng, '2026-10-01', $this->leave['annual']);

        $this->putDay($eng, '2026-10-01', ['version' => 1, 'site_id' => $this->leave['annual'], 'note' => 'n'])->assertOk()
            ->assertJsonPath('data.source', 'leave')->assertJsonPath('data.note', 'n');
        $this->putDay($eng, '2026-10-01', ['version' => 2, 'site_id' => $this->site])->assertOk()->assertJsonPath('data.source', 'entered');
    }

    public function test_a_leave_day_over_the_same_leave_type_keeps_hrs_source(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, '2026-10-01', ['site_id' => $this->leave['annual']])->assertOk();

        app(AttendanceDays::class)->writeLeaveDay($eng, '2026-10-01', $this->leave['annual']);

        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-01")->assertJsonPath('data.source', 'entered')->assertJsonPath('data.version', 1);
    }
}
