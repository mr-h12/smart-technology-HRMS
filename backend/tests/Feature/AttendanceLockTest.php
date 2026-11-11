<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceLockTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    private string $eng;

    private string $site;

    private string $holidayId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-27 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->eng = $this->employee('engineer');
        $this->holidayId = $this->holiday('2026-10-06');
        $this->site = $this->workSite();
    }

    /** @param  array<string, mixed>  $body */
    private function lock(array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', ...$body]);
    }

    public function test_lock_refuses_a_permanent_workers_day_with_an_arrival_and_no_departure(): void
    {
        $worker = $this->employee('permanent_worker');
        $p = Period::containing(EmployeeType::PermanentWorker, '2026-10-20');
        $this->putDay($worker, '2026-10-20', ['site_id' => $this->site, 'in_time' => '08:00'])->assertOk();   // the site manager's morning save

        $lock = fn () => $this->postJson('/api/v1/attendance/locks', ['group' => 'permanent_worker', 'period_start' => $p->start, 'confirm_missing' => true]);
        $lock()->assertStatus(422)->assertJsonPath('error.code', 'open_days');
        $this->putDay($worker, '2026-10-20', ['version' => 1, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $lock()->assertSuccessful();
    }

    public function test_lock_refuses_an_engineers_day_with_an_arrival_and_no_departure(): void
    {
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site, 'in_time' => '08:00'])->assertOk();
        $this->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'open_days');
        $this->putDay($this->eng, '2026-10-01', ['version' => 1, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $this->lock(['confirm_missing' => true])->assertSuccessful();
    }

    public function test_lock_lists_missing_days_and_needs_confirmation(): void
    {
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site]);
        $this->getJson('/api/v1/attendance/missing?group=engineer&period_start=2026-09-26')->assertJsonPath('meta.pagination.total', 24);
        $this->lock()->assertStatus(422)->assertJsonPath('error.code', 'missing_days_unconfirmed');
        $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.is_locked', true);
    }

    public function test_the_lock_still_counts_and_stores_the_days_of_an_archived_employee(): void
    {
        $left = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-20']);
        $this->putDay($left, '2026-10-05', ['site_id' => $this->site, 'in_time' => '08:00'])->assertOk();

        $this->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'open_days');
        $this->putDay($left, '2026-10-05', ['version' => 1, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $this->lock(['confirm_missing' => true])->assertCreated();
        // the Fridays and the holiday up to the termination date, none after it
        $this->assertSame(['2026-10-02', '2026-10-06', '2026-10-09', '2026-10-16'],
            AttendanceDay::where(['employee_id' => $left, 'source' => 'derived'])->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
    }

    public function test_the_missing_days_of_an_archived_employee_alone_still_need_confirming(): void
    {
        $this->employee('permanent_worker', '2026-01-01', ['termination_date' => '2026-10-20']);   // the group's only member, archived

        $this->lock(['group' => 'permanent_worker'])->assertStatus(422)->assertJsonPath('error.code', 'missing_days_unconfirmed');
    }

    public function test_lock_materializes_derived_days_as_stored_rows(): void
    {
        $this->lock(['confirm_missing' => true]);
        $this->assertSame(['2026-10-02', '2026-10-06', '2026-10-09', '2026-10-16', '2026-10-23'],
            AttendanceDay::where('source', 'derived')->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        app(BusinessRules::class)->update(['weekly_off_field' => 'thursday']);
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-02")->assertJsonPath('data.state', 'stored')->assertJsonPath('data.source', 'derived');
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-08")->assertJsonPath('data.state', 'missing'); // a locked period never re-derives
    }

    public function test_a_materialized_day_stores_what_save_stores_for_a_leave_day(): void
    {
        $worker = $this->employee('permanent_worker');
        $this->lock(['group' => 'permanent_worker', 'confirm_missing' => true])->assertCreated();
        $this->getJson("/api/v1/attendance/days/{$worker}/2026-10-02")->assertJsonPath('data.state', 'stored')->assertJsonPath('data.source', 'derived')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.site_id', $this->leave['friday'])->assertJsonPath('data.leave_code', 'friday')
            ->assertJsonPath('data.transport', 'none')->assertJsonPath('data.scheduled_in', null)->assertJsonPath('data.scheduled_out', null);
        $this->getJson("/api/v1/attendance/days/{$worker}/2026-10-06")->assertJsonPath('data.site_id', $this->leave['official_holiday']);
    }

    public function test_a_cleared_derived_day_is_restored_on_lock(): void
    {
        $this->putDay($this->eng, '2026-10-02', ['site_id' => $this->site]);
        $this->deleteJson("/api/v1/attendance/days/{$this->eng}/2026-10-02?version=1")->assertOk()->assertJsonPath('data.state', 'derived');
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-02")->assertJsonPath('data.source', 'derived')
            ->assertJsonPath('data.site_id', $this->leave['friday'])->assertJsonPath('data.version', 3);
    }

    public function test_writes_to_a_locked_period_are_refused(): void
    {
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site]);
        $this->lock(['confirm_missing' => true]);
        $this->putDay($this->eng, '2026-10-04', ['site_id' => $this->site])->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        $this->deleteJson("/api/v1/attendance/days/{$this->eng}/2026-10-01?version=1")->assertStatus(423);
        $this->lock(['confirm_missing' => true])->assertStatus(409)->assertJsonPath('error.code', 'period_already_locked');
    }

    public function test_reopen_requires_a_reason_and_keeps_materialized_rows(): void
    {
        $this->lock(['confirm_missing' => true]);
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction'])
            ->assertOk()->assertJsonPath('data.is_locked', false)->assertJsonPath('data.lock.reopen_reason', 'Correction');
        $this->assertSame(5, AttendanceDay::where('source', 'derived')->count());
        $this->putDay($this->eng, '2026-10-04', ['site_id' => $this->site])->assertOk();
        $this->lock(['confirm_missing' => true])->assertCreated();
    }

    public function test_reopening_an_open_period_is_refused(): void
    {
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction'])
            ->assertStatus(422)->assertJsonPath('error.code', 'period_not_locked');
    }

    public function test_a_period_start_that_is_not_one_is_refused(): void
    {
        $this->lock(['period_start' => '2026-10-01', 'confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.details.0.field', 'period_start');
    }

    public function test_lock_refuses_a_derived_day_without_a_leave_type(): void
    {
        Site::whereKey($this->leave['friday'])->update(['active' => false]);
        $this->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'leave_type_missing');
        $this->assertSame(0, AttendanceDay::count());                    // nothing materialized
    }

    public function test_lock_refuses_a_weekly_off_weekday_that_has_no_leave_type(): void
    {
        app(BusinessRules::class)->update(['weekly_off_field' => 'friday,thursday']);
        $this->withHeader('Accept-Language', 'ar')->lock(['confirm_missing' => true])->assertStatus(422)->assertJsonPath('error.code', 'leave_type_missing')
            ->assertJsonPath('error.message', 'لا يوجد نوع إجازة نشط بالكود thursday.');
    }

    public function test_locking_needs_attendance_lock(): void
    {
        $this->signIn('ceo');
        $this->lock(['confirm_missing' => true])->assertStatus(403);
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction'])->assertStatus(403);
    }

    public function test_lock_reopen_and_holidays_are_audited(): void
    {
        $this->lock(['confirm_missing' => true]);
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction']);
        $this->lock(['confirm_missing' => true]);
        $locks = DB::table('audit_log')->where('event', 'ATTENDANCE_LOCKED');
        $first = (clone $locks)->whereNull('old_values')->sole();
        $again = (clone $locks)->whereNotNull('old_values')->sole();
        $this->assertEquals(['employee_type' => 'engineer', 'period_start' => '2026-09-26', 'is_locked' => true, 'materialized' => 5, 'holidays_removed' => 0, 'holidays_added' => 0], json_decode($first->new_values, true));
        $this->assertEquals(['is_locked' => false], json_decode($again->old_values, true));
        $this->assertEquals(['employee_type' => 'engineer', 'period_start' => '2026-09-26', 'is_locked' => true, 'materialized' => 0, 'holidays_removed' => 0, 'holidays_added' => 0], json_decode($again->new_values, true));
        $reopened = DB::table('audit_log')->where('event', 'ATTENDANCE_REOPENED')->first();
        $this->assertEquals(['is_locked' => true], json_decode($reopened->old_values, true));
        $this->assertEquals(['is_locked' => false, 'reason' => 'Correction'], json_decode($reopened->new_values, true));
        $this->assertSame(5, DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'CREATED'])->count());
        $this->assertTrue(DB::table('audit_log')->where(['entity_type' => 'official_holidays', 'event' => 'CREATED'])->exists());
    }

    private function reopen(): void
    {
        $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => '2026-09-26', 'reason' => 'Correction'])->assertOk();
    }

    /** @return array<string, string> date => "leave_code/source" of the employee's live days on a leave type */
    private function leaveDays(string $employeeId): array
    {
        return DB::table('attendance_days')->join('sites', 'sites.id', '=', 'attendance_days.site_id')->where('employee_id', $employeeId)
            ->whereNull('attendance_days.deleted_at')->whereNotNull('leave_code')->orderBy('date')->get(['date', 'leave_code', 'source'])
            ->mapWithKeys(fn (object $r) => [substr((string) $r->date, 0, 10) => "{$r->leave_code}/{$r->source}"])->all();
    }

    private function missing(): int
    {
        return (int) $this->getJson('/api/v1/attendance/missing?group=engineer&period_start=2026-09-26')->json('meta.pagination.total');
    }

    public function test_deleting_a_holiday_removes_its_stored_derived_days_only(): void
    {
        $other = $this->employee('engineer');
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();
        $this->putDay($this->eng, '2026-10-06', ['version' => 1, 'site_id' => $this->site])->assertOk();
        $row = AttendanceDay::where(['employee_id' => $other, 'date' => '2026-10-06'])->sole();
        $missing = $this->missing();

        $this->deleteJson("/api/v1/attendance/holidays/{$this->holidayId}")->assertSuccessful();
        $this->assertSoftDeleted('attendance_days', ['id' => $row->id, 'version' => 2]);
        $this->assertTrue(DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'entity_id' => $row->id, 'event' => 'DELETED'])->exists());
        $this->assertSame(1, DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'entity_id' => $row->id, 'event' => 'UPDATED'])->count());
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-06")->assertJsonPath('data.source', 'entered')->assertJsonPath('data.site_id', $this->site);
        $this->getJson("/api/v1/attendance/days/{$other}/2026-10-06")->assertJsonPath('data.state', 'missing');
        $this->assertSame($missing + 1, $this->missing());
        $this->assertSame(4, AttendanceDay::where(['employee_id' => $other, 'source' => 'derived'])->count());   // the Fridays stay
    }

    public function test_moving_a_holiday_clears_the_old_date(): void
    {
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();

        $this->patchJson("/api/v1/attendance/holidays/{$this->holidayId}", ['date' => '2026-10-07', 'end_date' => '2026-10-07'])->assertOk();
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-06")->assertJsonPath('data.state', 'missing');
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-07")->assertJsonPath('data.state', 'derived')->assertJsonPath('data.leave_code', 'official_holiday');
    }

    /** 2026-10-12..15 is Monday to Thursday. */
    public function test_shortening_a_holiday_clears_the_stored_days_of_the_dropped_dates_only(): void
    {
        $other = $this->employee('engineer');
        $id = $this->holiday('2026-10-12', 'Holiday-2', '2026-10-15');
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();
        $this->putDay($this->eng, '2026-10-14', ['version' => 1, 'site_id' => $this->site])->assertOk();
        app(AttendanceDays::class)->save($this->eng, '2026-10-15', ['version' => 1, 'site_id' => $this->site], null, 'imported');
        app(AttendanceDays::class)->writeLeaveDay($other, '2026-10-15', $this->leave['annual']);
        $span = fn (string $e) => array_filter($this->leaveDays($e), fn (string $d) => $d >= '2026-10-12' && $d <= '2026-10-15', ARRAY_FILTER_USE_KEY);
        $kept = fn () => AttendanceDay::where('employee_id', $this->eng)->whereIn('date', ['2026-10-14', '2026-10-15'])->orderBy('date')->pluck('source')->all();

        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['date' => '2026-10-12', 'end_date' => '2026-10-13'])->assertOk();
        $this->assertSame(['2026-10-12' => 'official_holiday/derived', '2026-10-13' => 'official_holiday/derived', '2026-10-15' => 'annual/leave'], $span($other));
        $this->assertSame(['2026-10-12' => 'official_holiday/derived', '2026-10-13' => 'official_holiday/derived'], $span($this->eng));
        $this->assertSame(['entered', 'imported'], $kept());

        $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertNoContent();
        $this->assertSame(['2026-10-15' => 'annual/leave'], $span($other));
        $this->assertSame([], $span($this->eng));
        $this->assertSame(['entered', 'imported'], $kept());
    }

    public function test_relock_lists_every_day_of_a_holiday_added_since_and_the_leave_inside_it(): void
    {
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();
        $this->cairo('2026-10-27 13:00');
        $this->putDay($this->eng, '2026-10-13', ['site_id' => $this->leave['casual']])->assertOk();
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-12', 'end_date' => '2026-10-14', 'name' => 'Holiday-2'])->assertCreated();
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-24', 'end_date' => '2026-10-27', 'name' => 'Holiday-3'])->assertCreated();

        $this->lock(['confirm_missing' => true])->assertCreated()   // only the days inside the locked period, which ends on the 25th
            ->assertJsonPath('data.holidays_added', ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-24', '2026-10-25'])
            ->assertJsonCount(1, 'data.leave_on_holidays')->assertJsonPath('data.leave_on_holidays.0.date', '2026-10-13')
            ->assertJsonPath('data.leave_on_holidays.0.leave_code', 'casual');
    }

    public function test_relock_lists_removed_and_added_holidays(): void
    {
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();
        $this->cairo('2026-10-27 13:00');
        $this->patchJson("/api/v1/attendance/holidays/{$this->holidayId}", ['date' => '2026-10-07', 'end_date' => '2026-10-07'])->assertOk();

        $this->lock()->assertStatus(422)->assertJsonPath('error.code', 'missing_days_unconfirmed');
        $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.holidays_removed', ['2026-10-06'])->assertJsonPath('data.holidays_added', ['2026-10-07'])
            ->assertJsonPath('data.leave_on_holidays', []);
        $this->getJson("/api/v1/attendance/days/{$this->eng}/2026-10-07")->assertJsonPath('data.source', 'derived')->assertJsonPath('data.leave_code', 'official_holiday');
        $audit = DB::table('audit_log')->where('event', 'ATTENDANCE_LOCKED')->whereNotNull('old_values')->sole();
        $this->assertSame([1, 1], [json_decode($audit->new_values, true)['holidays_removed'], json_decode($audit->new_values, true)['holidays_added']]);
    }

    public function test_a_removed_holiday_on_a_weekly_off_becomes_the_weekly_off(): void
    {
        $friday = $this->holiday('2026-10-09', 'Holiday-2');
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->assertSame('official_holiday/derived', $this->leaveDays($this->eng)['2026-10-09']);
        $this->reopen();
        $this->cairo('2026-10-27 13:00');
        $this->deleteJson("/api/v1/attendance/holidays/{$friday}")->assertSuccessful();

        $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.holidays_removed', ['2026-10-09'])->assertJsonPath('data.holidays_added', []);
        $this->assertSame('friday/derived', $this->leaveDays($this->eng)['2026-10-09']);
    }

    public function test_relock_never_touches_weekly_off_rows(): void
    {
        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->reopen();
        $fridays = AttendanceDay::where('site_id', $this->leave['friday'])->orderBy('date')->get(['date', 'version'])->toArray();
        app(BusinessRules::class)->update(['weekly_off_field' => 'saturday']);
        $this->deleteJson("/api/v1/attendance/holidays/{$this->holidayId}")->assertSuccessful();

        $this->lock(['confirm_missing' => true])->assertCreated();
        $this->assertCount(4, $fridays);
        $this->assertSame($fridays, AttendanceDay::where('site_id', $this->leave['friday'])->orderBy('date')->get(['date', 'version'])->toArray());
    }

    public function test_lock_lists_leave_on_a_holiday_without_changing_it(): void
    {
        $id = $this->postJson('/api/v1/leave/requests', ['employee_id' => $this->eng, 'kind' => 'annual', 'from_date' => '2026-10-12', 'to_date' => '2026-10-12'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/leave/requests/{$id}/approve", ['version' => 1])->assertOk();
        $this->putDay($this->eng, '2026-10-13', ['site_id' => $this->leave['casual']])->assertOk();
        $this->holiday('2026-10-12', 'Holiday-2');
        $this->holiday('2026-10-13', 'Holiday-3');

        $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.leave_on_holidays', [
            ['employee_id' => $this->eng, 'code' => DB::table('employees')->where('id', $this->eng)->value('code'), 'name_ar' => 'Engineer-1', 'name_en' => null, 'date' => '2026-10-12', 'leave_code' => 'annual'],
            ['employee_id' => $this->eng, 'code' => DB::table('employees')->where('id', $this->eng)->value('code'), 'name_ar' => 'Engineer-1', 'name_en' => null, 'date' => '2026-10-13', 'leave_code' => 'casual'],
        ]);
        $days = $this->leaveDays($this->eng);
        $this->assertSame(['annual/leave', 'casual/entered'], [$days['2026-10-12'], $days['2026-10-13']]);
    }

    public function test_a_first_lock_reports_no_holiday_changes(): void
    {
        $this->lock(['confirm_missing' => true])->assertCreated()
            ->assertJsonPath('data.holidays_removed', [])->assertJsonPath('data.holidays_added', [])->assertJsonPath('data.leave_on_holidays', []);
    }
}
