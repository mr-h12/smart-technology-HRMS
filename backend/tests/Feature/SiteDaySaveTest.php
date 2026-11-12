<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The site manager's save of a site's day (manager-attendance plan, Task 8, with the 2026-11-04 amendments). */
class SiteDaySaveTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private User $m;

    private string $w1;

    private string $w2;

    private string $l1;

    private string $e1;

    /** @var array<string, string> */
    private array $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        [$this->s1, $this->s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->homeSite($this->w1 = $this->employee('permanent_worker'), $this->s1, '2026-10-01');
        $this->homeSite($this->w2 = $this->employee('permanent_worker'), $this->s2, '2026-10-01');
        $this->homeSite($this->l1 = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->e1 = $this->employee('engineer');
        app(EmployeeService::class)->assignManager($this->e1, ['manager_id' => $this->m->id, 'effective_from' => '2026-10-01']);
    }

    public function test_the_read_gives_each_row_its_shift_and_whether_it_can_be_edited(): void
    {
        $this->actingAs($this->m, 'api');
        $r = $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}")->assertOk();
        $this->assertEqualsCanonicalizing(['absent', 'sick', 'rest', 'other'], array_keys($r->json('data.leave_day_types')));
        $this->assertSame($this->leave['sick'], $r->json('data.leave_day_types.sick'));
        $row = $r->json('data.rows.0');

        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $row['shift']);   // the field shift setting (R19 open)
        $this->assertSame([true, null, false, null], [$row['can_edit'], $row['why_not'], $row['departure_only'], $row['open_site_id']]);
    }

    public function test_morning_then_evening_saves_the_times_with_source_manager(): void
    {
        $this->actingAs($this->m, 'api');
        $r = $this->save($this->s1, [
            $this->work($this->w1, '08:00'),
            $this->work($this->l1, '08:20'),
            $this->work($this->e1, '08:00'),
        ])->assertOk();
        $this->assertSame([1, 1, 1], $r->json('data.rows.*.day.version'));
        $this->assertSame([null, null, null], $r->json('data.rows.*.day.out_time'));
        $this->assertSame(3, DB::table('attendance_days')->where(['date' => '2026-10-22', 'source' => 'manager'])->count());
        $this->assertSame(20, $this->stored($this->l1)->late_minutes);            // raw minutes: the grace is taken at payroll (R18 default)
        $this->assertNull($this->stored($this->l1)->ot_minutes);

        $this->cairo('2026-10-22 17:00');
        $this->save($this->s1, [
            $this->work($this->w1, '08:00', '15:00', 1),                          // left early
            $this->work($this->l1, '08:20', '18:30', 1),                          // stayed late
            $this->work($this->e1, '08:00', '17:00', 1),
        ])->assertOk();
        $this->assertSame(['15:00:00', '18:30:00', '17:00:00'], [$this->stored($this->w1)->out_time, $this->stored($this->l1)->out_time, $this->stored($this->e1)->out_time]);
        $this->assertSame(0, $this->stored($this->l1)->ot_minutes);               // held until HR approves overtime
    }

    public function test_absent_sick_rest_and_other_are_allowed_and_request_leave_is_not(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [['employee_id' => $this->w1, 'site_id' => $this->leave['absent']], ['employee_id' => $this->l1, 'site_id' => $this->leave['sick']]])->assertOk();
        foreach (['annual', 'casual', 'unpaid', 'friday'] as $code) {
            $this->save($this->s1, [['employee_id' => $this->e1, 'site_id' => $this->leave[$code]]])->assertStatus(422)
                ->assertJsonPath('error.code', 'site_not_for_manager')->assertJsonPath('error.details.0.field', 'rows.0');
        }
        $this->save($this->s1, [['employee_id' => $this->e1, 'site_id' => $this->s2]])->assertStatus(422)->assertJsonPath('error.details.0.code', 'site_not_for_manager');
    }

    public function test_yesterday_is_writable_until_noon_only(): void
    {
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-22 11:59');
        $this->save($this->s1, [$this->work($this->w1, '08:00', '16:00')], '2026-10-21')->assertOk();
        $this->cairo('2026-10-22 12:00');
        $this->save($this->s1, [$this->work($this->w1, '08:30', '16:00', 1)], '2026-10-21')->assertStatus(422)
            ->assertJsonPath('error.code', 'outside_manager_window')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}&date=2026-10-21")->assertOk()->assertJsonPath('data.rows.0.why_not', 'window');
        $this->save($this->s1, [$this->work($this->w1, '08:00')], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'outside_manager_window');
        $this->save($this->s1, [$this->work($this->w1, '08:00')], '2026-10-23')->assertStatus(422)->assertJsonPath('error.code', 'future_date');
    }

    public function test_two_site_managers_on_one_site_the_second_save_is_a_conflict(): void
    {
        $other = $this->managerOf($this->s1);
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertOk();
        $this->actingAs($other, 'api');
        $this->save($this->s1, [$this->work($this->w1, '09:00')])->assertStatus(409);
        $this->assertSame('08:00:00', $this->stored($this->w1)->in_time);
    }

    public function test_a_body_cannot_reach_hidden_fields_or_other_sites(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [[...$this->work($this->w1, '08:00', '16:00'), 'transport' => 'none', 'advance_amount' => '500', 'late_minutes' => 99]])->assertOk();
        $day = $this->stored($this->w1);
        $this->assertSame(['full', null, 'manager'], [$day->transport, $day->advance_amount, $day->source]);   // the worker default, not the body's

        $this->save($this->s1, [$this->work($this->w2, '08:00')])->assertNotFound();                       // Site-2's worker on Site-1's list
        $s3 = $this->workSite('Site-3');
        $this->managerOf($s3);
        $this->save($s3, [$this->work($this->w1, '08:00')])->assertNotFound();                             // another manager's site
    }

    public function test_a_day_hr_recorded_is_hrs_to_change_or_clear(): void
    {
        $this->putDay($this->w1, '2026-10-22', ['site_id' => $this->leave['annual']])->assertOk();
        $this->putDay($this->e1, '2026-10-22', ['site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '18:00', 'ot_minutes' => 120])->assertOk();
        $this->actingAs($this->m, 'api');
        $rows = collect($this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}")->json('data.rows'))->keyBy('employee.id');
        $this->assertSame([false, 'hr_day'], [$rows[$this->w1]['can_edit'], $rows[$this->w1]['why_not']]);
        $this->assertSame([false, 'hr_day'], [$rows[$this->e1]['can_edit'], $rows[$this->e1]['why_not']]);

        $this->save($this->s1, [$this->work($this->w1, '08:00', null, 1)])->assertStatus(422)
            ->assertJsonPath('error.code', 'hr_day')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->save($this->s1, [['employee_id' => $this->w1, 'version' => 1, 'clear' => true]])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
        $this->save($this->s1, [$this->work($this->e1, '08:00', '16:00', 1)])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
        $this->assertSame(120, $this->stored($this->e1)->ot_minutes);                                    // HR's overtime stays
    }

    public function test_a_sick_day_from_an_approved_request_is_protected(): void
    {
        app(AttendanceDays::class)->writeLeaveDay($this->l1, '2026-10-22', $this->leave['sick']);
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [$this->work($this->l1, '08:00', null, 1)])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
        $this->assertSame('leave', $this->stored($this->l1)->source);
    }

    public function test_the_site_manager_clears_only_their_own_day_at_this_site(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s2, [['employee_id' => $this->e1, 'site_id' => $this->s2, 'in_time' => '08:00']])->assertOk();   // the engineer, at Site-2
        $this->save($this->s1, [['employee_id' => $this->e1, 'version' => 1, 'clear' => true]])->assertStatus(422)
            ->assertJsonPath('error.code', 'clear_not_allowed')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->save($this->s2, [['employee_id' => $this->e1, 'version' => 1, 'clear' => true]])->assertOk();
        $this->assertSame(0, DB::table('attendance_days')->where(['employee_id' => $this->e1, 'date' => '2026-10-22'])->whereNull('deleted_at')->count());
    }

    public function test_a_present_day_needs_its_arrival(): void
    {
        $this->actingAs($this->m, 'api');
        // every refused row is a detail with its code, and the first one names the error
        $this->save($this->s1, [$this->work($this->e1, null, '16:00'), $this->work($this->w1, '08:00'), $this->work($this->l1, null)])->assertStatus(422)
            ->assertJsonPath('error.code', 'arrival_required')
            ->assertJsonPath('error.details.*.field', ['rows.0', 'rows.2'])->assertJsonPath('error.details.*.code', ['arrival_required', 'arrival_required']);
        $this->assertSame(0, DB::table('attendance_days')->count());                                       // all or nothing
    }

    public function test_an_earlier_open_day_comes_first(): void
    {
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-21 09:00');
        $this->save($this->s1, [$this->work($this->e1, '08:00')], '2026-10-21')->assertOk();             // no departure
        $this->cairo('2026-10-22 09:00');
        $row = $this->row($this->s1, $this->e1);
        $this->assertSame(['open_day', '2026-10-21', $this->s1], [$row['why_not'], $row['open_date'], $row['open_site_id']]);
        $this->save($this->s1, [$this->work($this->e1, '08:00')])->assertStatus(422)
            ->assertJsonPath('error.code', 'open_earlier_day')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertOk();                          // the others are not held up
        $this->save($this->s1, [$this->work($this->e1, '08:00', '16:00', 1)], '2026-10-21')->assertOk();   // completed in the window
        $this->save($this->s1, [$this->work($this->e1, '08:00')])->assertOk();
    }

    public function test_after_the_window_the_site_manager_enters_only_the_departure_and_hr_hears_at_once(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-20 09:00');
        $this->save($this->s1, [$this->work($this->e1, '08:10'), $this->work($this->w1, '08:00', '16:00')], '2026-10-20')->assertOk();
        $this->cairo('2026-10-22 13:00');                                                                // two days later, past noon

        $rows = collect($this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}&date=2026-10-20")->json('data.rows'))->keyBy('employee.id');
        $this->assertSame([true, null, true], [$rows[$this->e1]['can_edit'], $rows[$this->e1]['why_not'], $rows[$this->e1]['departure_only']]);
        $this->assertSame([false, 'window'], [$rows[$this->w1]['can_edit'], $rows[$this->w1]['why_not']]);   // complete: nothing left to add
        $this->assertSame($this->s1, $this->row($this->s1, $this->e1)['open_site_id']);                    // today's row links to it

        $this->save($this->s1, [$this->work($this->e1, '08:00', '16:00', 1)], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'departure_only');   // the arrival stays
        $this->save($this->s1, [['employee_id' => $this->e1, 'version' => 1, 'site_id' => $this->leave['absent']]], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'departure_only');
        $this->save($this->s1, [[...$this->work($this->e1, '08:10', '16:00', 1), 'note' => 'Note-1']], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'departure_only');
        $this->save($this->s1, [$this->work($this->w1, '08:00', '17:00', 1)], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'outside_manager_window');

        $audits = DB::table('audit_log')->where('entity_type', 'attendance_days')->count();
        $this->save($this->s1, [$this->work($this->e1, '08:10', '16:00', 1)], '2026-10-20')->assertOk();
        $day = DB::table('attendance_days')->where(['employee_id' => $this->e1, 'date' => '2026-10-20'])->first();
        $this->assertSame(['08:10:00', '16:00:00', 10, 0, 'manager'], [$day?->in_time, $day?->out_time, $day?->late_minutes, $day?->ot_minutes, $day?->source]);
        $this->assertSame($audits + 1, DB::table('audit_log')->where('entity_type', 'attendance_days')->count());
        $n = DB::table('notifications')->where(['user_id' => $hr->id, 'type' => 'attendance_past_edit'])->first();
        $this->assertSame((string) $day?->id, $n?->ref);
        $this->assertEquals(['employee_id' => $this->e1, 'date' => '2026-10-20', 'out_time' => '16:00', 'site_name_ar' => 'Site-1', 'site_name_en' => null, 'group' => 'engineer', 'period_start' => '2026-09-26'], json_decode((string) $n?->data, true));
        $this->save($this->s1, [$this->work($this->e1, '08:00')])->assertOk();                          // today is no longer held up

        $this->actingAs($hr, 'api');
        $this->putDay($this->e1, '2026-10-20', ['version' => 2, 'site_id' => $this->s1, 'in_time' => '08:10', 'out_time' => '15:30'])->assertOk();   // HR corrects it
    }

    public function test_before_noon_yesterdays_departure_is_not_a_past_day_edit(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-21 09:00');
        $this->save($this->s1, [$this->work($this->e1, '08:00')], '2026-10-21')->assertOk();
        $this->cairo('2026-10-22 11:00');
        $this->save($this->s1, [$this->work($this->e1, '08:00', '16:00', 1)], '2026-10-21')->assertOk();
        $this->assertSame(0, DB::table('notifications')->where(['user_id' => $hr->id, 'type' => 'attendance_past_edit'])->count());
    }

    public function test_an_open_day_hr_recorded_blocks_and_hr_completes_it(): void
    {
        $this->putDay($this->e1, '2026-10-21', ['site_id' => $this->s1, 'in_time' => '08:00'])->assertOk();
        $this->actingAs($this->m, 'api');
        $row = $this->row($this->s1, $this->e1);
        $this->assertSame(['open_day', '2026-10-21', null], [$row['why_not'], $row['open_date'], $row['open_site_id']]);
        $this->save($this->s1, [$this->work($this->e1, '08:00', '16:00', 1)], '2026-10-21')->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
    }

    public function test_an_open_day_outside_the_managers_reach_that_day_is_hrs(): void
    {
        // Engineer-2 was another site manager's on 10-21, and that manager left them open at the shared Site-1; this manager has them from 10-22.
        $other = $this->managerOf($this->s1);
        $e2 = $this->employee('engineer');
        app(EmployeeService::class)->assignManager($e2, ['manager_id' => $other->id, 'effective_from' => '2026-10-01']);
        $this->actingAs($other, 'api');
        $this->cairo('2026-10-21 09:00');
        $this->save($this->s1, [$this->work($e2, '08:00')], '2026-10-21')->assertOk();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        app(EmployeeService::class)->assignManager($e2, ['manager_id' => $this->m->id, 'effective_from' => '2026-10-22']);
        $this->actingAs($this->m, 'api');
        $row = $this->row($this->s1, $e2);
        $this->assertSame(['open_day', null], [$row['why_not'], $row['open_site_id']]);
    }

    public function test_an_open_day_in_a_locked_period_never_blocks(): void
    {
        // An arrival-only day from before the open-day rule, in a period HR has since locked (PR #22 review B2).
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-08-26', 'confirm_missing' => true])->assertCreated();
        DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $this->e1, 'date' => '2026-09-20', 'site_id' => $this->s1, 'in_time' => '08:00', 'source' => 'entered', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->m, 'api');
        $this->assertNull($this->row($this->s1, $this->e1)['why_not']);
        $this->save($this->s1, [$this->work($this->e1, '08:00')])->assertOk();
        $this->artisan('attendance:alert-missing', ['date' => '2026-10-22'])->assertSuccessful();
        $groups = collect(json_decode((string) DB::table('notifications')->where('type', 'attendance_missing_days')->value('data'), true)['groups'])->where('group', 'engineer');
        $this->assertSame([1], $groups->pluck('open')->filter()->values()->all());                       // today's open day only, not September's
    }

    public function test_an_approved_sick_request_over_the_site_managers_sick_day_takes_it_over(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [['employee_id' => $this->l1, 'site_id' => $this->leave['sick']]])->assertOk();
        $this->assertSame('manager', $this->stored($this->l1)->source);
        app(AttendanceDays::class)->writeLeaveDay($this->l1, '2026-10-22', $this->leave['sick']);          // HR approves a sick request for that day
        $this->assertSame('leave', $this->stored($this->l1)->source);
        $this->assertSame('hr_day', $this->row($this->s1, $this->l1)['why_not']);
        $this->save($this->s1, [$this->work($this->l1, '08:00', null, 1)])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
        $this->save($this->s1, [['employee_id' => $this->l1, 'version' => 1, 'clear' => true]])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
    }

    public function test_hr_hears_of_open_days_in_the_evening_alert(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [$this->work($this->e1, '08:00'), $this->work($this->l1, '08:00', '16:00')])->assertOk();
        $this->artisan('attendance:alert-missing', ['date' => '2026-10-22'])->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->whereIn('type', ['attendance_open_days', 'crew_changes'])->count());   // one alert, not three
        $groups = collect(json_decode((string) DB::table('notifications')->where(['user_id' => $hr->id, 'type' => 'attendance_missing_days'])->value('data'), true)['groups'])->keyBy(fn (array $g) => $g['group'].$g['period_start']);
        $this->assertSame(1, $groups['engineer2026-09-26']['open']);
        $this->assertSame(0, $groups['day_laborer2026-10-16']['open']);
        $this->assertArrayNotHasKey('office2026-10-01', $groups->all());                                   // nothing to report there
    }

    public function test_previous_is_the_latest_day_in_the_week_before_skipping_fridays_and_holidays(): void
    {
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-22 09:00');
        $this->save($this->s1, [$this->work($this->w1, '08:10', '16:00'), ['employee_id' => $this->l1, 'site_id' => $this->leave['sick']]])->assertOk();
        $this->cairo('2026-10-24 09:00');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->putDay($this->w1, '2026-10-23', ['site_id' => $this->leave['friday']])->assertOk();      // a Friday leave is skipped
        $this->actingAs($this->m, 'api');
        $rows = collect($this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}")->assertOk()->json('data.rows'))->keyBy('employee.id');
        $this->assertSame(['date' => '2026-10-22', 'site_id' => $this->s1, 'leave_code' => null, 'in_time' => '08:10', 'out_time' => '16:00'], $rows[$this->w1]['previous']);
        $this->assertSame('sick', $rows[$this->l1]['previous']['leave_code']);
        $this->assertNull($rows[$this->e1]['previous']);
    }

    public function test_previous_skips_an_official_holiday(): void
    {
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-20 09:00');
        $this->save($this->s1, [$this->work($this->w1, '08:10', '16:00')], '2026-10-20')->assertOk();
        $this->cairo('2026-10-22 09:00');
        $this->holiday('2026-10-21');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->putDay($this->w1, '2026-10-21', ['site_id' => $this->leave['official_holiday']])->assertOk();
        $this->actingAs($this->m, 'api');
        $this->assertSame('2026-10-20', $this->row($this->s1, $this->w1)['previous']['date']);
    }

    public function test_previous_looks_back_seven_days_and_no_further(): void
    {
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->putDay($this->w1, '2026-10-15', ['site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();   // D-7
        $this->putDay($this->l1, '2026-10-14', ['site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();   // D-8
        $this->actingAs($this->m, 'api');
        $this->assertSame('2026-10-15', $this->row($this->s1, $this->w1)['previous']['date']);
        $this->assertNull($this->row($this->s1, $this->l1)['previous']);
    }

    public function test_previous_shows_only_days_at_the_managers_sites_or_on_a_leave_day_type(): void
    {
        // Worker-3 worked at another manager's Site-3 until HR moved them to Site-1 today: Site-1's manager never sees that week.
        $s3 = $this->workSite('Site-3');
        $this->managerOf($s3);
        $this->homeSite($w3 = $this->employee('permanent_worker'), $s3, '2026-10-01');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->putDay($w3, '2026-10-20', ['site_id' => $s3, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $this->putDay($w3, '2026-10-21', ['site_id' => $s3, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $this->putDay($this->w1, '2026-10-21', ['site_id' => $this->leave['annual']])->assertOk();      // request-only leave: nothing to copy
        $this->homeSite($w3, $this->s1, '2026-10-22');
        $this->actingAs($this->m, 'api');
        $this->assertNull($this->row($this->s1, $w3)['previous']);
        $this->assertNull($this->row($this->s1, $this->w1)['previous']);

        $this->actingAs($this->makeUser('hr'), 'api');
        $this->putDay($w3, '2026-10-19', ['site_id' => $this->leave['sick']])->assertOk();             // a leave day-type shows wherever they were
        $this->actingAs($this->m, 'api');
        $this->assertSame(['2026-10-19', 'sick'], [$this->row($this->s1, $w3)['previous']['date'], $this->row($this->s1, $w3)['previous']['leave_code']]);
    }

    public function test_an_unchanged_arrival_keeps_its_late_minutes_and_an_unsent_note_stays(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [[...$this->work($this->l1, '08:20'), 'note' => 'Note-1']])->assertOk();
        $this->assertSame([20, 'Note-1'], [$this->stored($this->l1)->late_minutes, $this->stored($this->l1)->note]);
        DB::table('settings')->where('key', 'worker_shift_start')->update(['value' => '07:00']);
        BusinessRules::forgetCache();

        $this->save($this->s1, [$this->work($this->l1, '08:20', '16:00', 1)])->assertOk();               // only the departure changed
        $day = $this->stored($this->l1);
        $this->assertSame([20, 0, 'Note-1'], [$day->late_minutes, $day->ot_minutes, $day->note]);
        $this->save($this->s1, [$this->work($this->l1, '08:30', '16:00', 2)])->assertOk();               // a new arrival is recomputed
        $this->assertSame(90, $this->stored($this->l1)->late_minutes);
    }

    public function test_a_site_managers_overtime_is_stored_as_0_until_hr_approves_it(): void
    {
        $this->cairo('2026-10-22 19:00');
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [$this->work($this->e1, '08:00', '18:30')])->assertOk();
        $this->assertSame([0, 0], [$this->stored($this->e1)->late_minutes, $this->stored($this->e1)->ot_minutes]);

        $this->signIn('hr');
        $this->postJson('/api/v1/attendance/days/bulk', ['date' => '2026-10-22', 'rows' => [['employee_id' => $this->e1, 'version' => 1, 'site_id' => $this->s1, 'ot_minutes' => 90]]])->assertOk();
        $this->assertSame([0, 90], [$this->stored($this->e1)->late_minutes, $this->stored($this->e1)->ot_minutes]);
    }

    public function test_hr_and_ceo_cannot_save(): void
    {
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertForbidden();
        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertForbidden();
    }

    /** @return array<string, mixed> the row of $employeeId on $siteId's list today */
    private function row(string $siteId, string $employeeId): array
    {
        return collect($this->getJson("/api/v1/attendance/site-day?site_id={$siteId}")->assertOk()->json('data.rows'))->firstWhere('employee.id', $employeeId);
    }

    /** @return array<string, mixed> */
    private function work(string $employeeId, ?string $in, ?string $out = null, ?int $version = null): array
    {
        return ['employee_id' => $employeeId, 'version' => $version, 'site_id' => $this->s1, 'in_time' => $in, 'out_time' => $out];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function save(string $siteId, array $rows, string $date = '2026-10-22'): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day', ['site_id' => $siteId, 'date' => $date, 'rows' => $rows]);
    }

    private function stored(string $employeeId): object
    {
        return DB::table('attendance_days')->where(['employee_id' => $employeeId, 'date' => '2026-10-22'])->whereNull('deleted_at')->firstOrFail();
    }
}
