<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-27 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->eng = $this->employee('engineer');
        $this->holiday('2026-10-06');
        $this->site = $this->workSite();
    }

    /** @param  array<string, mixed>  $body */
    private function lock(array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', ...$body]);
    }

    public function test_lock_lists_missing_days_and_needs_confirmation(): void
    {
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site]);
        $this->getJson('/api/v1/attendance/missing?group=engineer&period_start=2026-09-26')->assertJsonPath('meta.pagination.total', 24);
        $this->lock()->assertStatus(422)->assertJsonPath('error.code', 'missing_days_unconfirmed');
        $this->lock(['confirm_missing' => true])->assertCreated()->assertJsonPath('data.is_locked', true);
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
        $this->assertEquals(['employee_type' => 'engineer', 'period_start' => '2026-09-26', 'is_locked' => true, 'materialized' => 5], json_decode($first->new_values, true));
        $this->assertEquals(['is_locked' => false], json_decode($again->old_values, true));
        $this->assertEquals(['employee_type' => 'engineer', 'period_start' => '2026-09-26', 'is_locked' => true, 'materialized' => 0], json_decode($again->new_values, true));
        $reopened = DB::table('audit_log')->where('event', 'ATTENDANCE_REOPENED')->first();
        $this->assertEquals(['is_locked' => true], json_decode($reopened->old_values, true));
        $this->assertEquals(['is_locked' => false, 'reason' => 'Correction'], json_decode($reopened->new_values, true));
        $this->assertSame(5, DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'CREATED'])->count());
        $this->assertTrue(DB::table('audit_log')->where(['entity_type' => 'official_holidays', 'event' => 'CREATED'])->exists());
    }
}
