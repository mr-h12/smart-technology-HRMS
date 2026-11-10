<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class OfficialHolidaysTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-02 12:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->employee('engineer');
        $this->employee('office');
    }

    public function test_holidays_crud(): void
    {
        $id = $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-06', 'name' => 'Holiday-1'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-06', 'name' => 'Holiday-2'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['name' => 'Holiday-3'])->assertOk()->assertJsonPath('data.name', 'Holiday-3');
        $this->getJson('/api/v1/attendance/holidays?year=2026')->assertJsonPath('data.0.date', '2026-10-06');
        $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertNoContent();
        $this->signIn('ceo');
        $this->getJson('/api/v1/attendance/holidays')->assertOk();
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-07', 'name' => 'Holiday-4'])->assertStatus(403);
    }

    public function test_a_holiday_without_an_end_date_is_one_day(): void
    {
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-12-10', 'name' => 'Holiday-1'])->assertCreated()
            ->assertJsonPath('data.date', '2026-12-10')->assertJsonPath('data.end_date', '2026-12-10');
    }

    public function test_a_patch_moves_both_dates_together_or_renames_only(): void
    {
        $id = $this->holiday('2026-12-10', 'Holiday-1', '2026-12-12');
        $patch = fn (array $body) => $this->patchJson("/api/v1/attendance/holidays/{$id}", $body);
        $patch(['name' => 'Holiday-2'])->assertOk()->assertJsonPath('data.name', 'Holiday-2')->assertJsonPath('data.end_date', '2026-12-12');
        $patch(['date' => '2026-12-11'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'end_date');
        $patch(['end_date' => '2026-12-13'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
        $patch(['date' => '2026-12-11', 'end_date' => '2026-12-14'])->assertOk()   // overlaps only itself
            ->assertJsonPath('data.date', '2026-12-11')->assertJsonPath('data.end_date', '2026-12-14');
    }

    /** 2026-10-09 is a Friday and 10-10 a Saturday: weekly off for the field types and for office staff. */
    public function test_every_day_of_a_period_is_an_official_holiday_for_field_and_office_staff(): void
    {
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-07', 'end_date' => '2026-10-10', 'name' => 'Holiday-1'])->assertCreated();
        $holiday = 'official_holiday';
        $want = ['2026-10-06' => null, '2026-10-07' => $holiday, '2026-10-08' => $holiday, '2026-10-09' => $holiday, '2026-10-10' => $holiday, '2026-10-11' => null];
        foreach (['engineer' => '2026-09-26', 'office' => '2026-10-01'] as $group => $start) {
            $days = array_column($this->getJson("/api/v1/attendance/grid?group={$group}&period_start={$start}")->assertOk()->json('data.0.days'), 'leave_code', 'date');
            $this->assertSame($want, array_intersect_key($days, $want), $group);
        }
    }

    public function test_holidays_never_overlap_and_last_at_most_31_days(): void
    {
        $post = fn (string $from, string $to) => $this->postJson('/api/v1/attendance/holidays', ['date' => $from, 'end_date' => $to, 'name' => 'Holiday-1']);
        $post('2026-12-10', '2026-12-13')->assertCreated();
        $this->withHeader('Accept-Language', 'ar');
        $post('2026-12-13', '2026-12-15')->assertStatus(422)->assertJsonPath('error.details.0.field', 'date')
            ->assertJsonPath('error.details.0.message', 'هذه التواريخ تتداخل مع إجازة رسمية أخرى.');
        $post('2026-12-01', '2026-12-31')->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
        $post('2026-12-14', '2026-12-16')->assertCreated();   // touching is allowed
        $post('2026-12-09', '2026-12-09')->assertCreated();
        $post('2026-12-20', '2026-12-19')->assertStatus(422)->assertJsonPath('error.details.0.field', 'end_date');
        $post('2027-01-01', '2027-02-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'end_date')   // 32 days
            ->assertJsonPath('error.details.0.message', 'لا تزيد مدة الإجازة الرسمية على 31 يومًا.');
        $post('2027-01-01', '2027-01-31')->assertCreated();
    }

    public function test_the_database_refuses_an_overlapping_holiday(): void
    {
        $this->holiday('2026-12-10', 'Holiday-1', '2026-12-12');
        $this->holiday('2026-12-13', 'Holiday-2');
        try {
            $this->holiday('2026-12-12', 'Holiday-3');
            $this->fail('An overlapping holiday was stored.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());   // the code OfficialHolidays answers as a 422 on date
        }
    }

    public function test_a_period_across_the_engineers_25th_needs_both_their_periods_open(): void
    {
        $body = ['date' => '2026-10-24', 'end_date' => '2026-10-27', 'name' => 'Holiday-1'];
        $id = $this->postJson('/api/v1/attendance/holidays', $body)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertNoContent();
        foreach (['2026-09-26', '2026-10-26'] as $start) {
            $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => $start, 'confirm_missing' => true])->assertCreated();
            $this->postJson('/api/v1/attendance/holidays', $body)->assertStatus(423);
            $this->postJson('/api/v1/attendance/locks/reopen', ['group' => 'engineer', 'period_start' => $start, 'reason' => 'Correction'])->assertOk();
        }
    }

    public function test_the_year_filter_lists_a_period_crossing_the_year_end_in_both_years(): void
    {
        $this->holiday('2026-12-30', 'Holiday-1', '2027-01-02');
        $this->holiday('2027-01-07', 'Holiday-2');
        $this->getJson('/api/v1/attendance/holidays?year=2026')->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.name', 'Holiday-1');
        $this->getJson('/api/v1/attendance/holidays?year=2027')->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.name', 'Holiday-1')->assertJsonPath('data.1.name', 'Holiday-2');
    }

    public function test_an_update_is_one_audit_entry_with_the_old_and_new_end_date(): void
    {
        $id = $this->holiday('2026-12-10', 'Holiday-1', '2026-12-12');
        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['date' => '2026-12-10', 'end_date' => '2026-12-14'])->assertOk();
        $audit = DB::table('audit_log')->where(['entity_type' => 'official_holidays', 'entity_id' => $id, 'event' => 'UPDATED'])->sole();
        $this->assertSame(['end_date' => '2026-12-12'], json_decode($audit->old_values, true));
        $this->assertSame(['end_date' => '2026-12-14'], json_decode($audit->new_values, true));
    }

    public function test_the_list_is_by_date_and_filtered_by_year(): void
    {
        $this->holiday('2026-12-01', 'Holiday-1');
        $this->holiday('2026-01-07', 'Holiday-2');
        $this->holiday('2027-01-07', 'Holiday-3');
        $this->getJson('/api/v1/attendance/holidays?year=2026')->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.name', 'Holiday-2')->assertJsonPath('data.1.name', 'Holiday-1');
        $this->getJson('/api/v1/attendance/holidays')->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_a_deleted_holidays_date_can_be_used_again(): void
    {
        $id = $this->holiday('2026-10-06');
        $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertNoContent();
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-06', 'name' => 'Holiday-2'])->assertCreated();
    }

    public function test_holiday_edits_inside_a_locked_period_are_refused(): void
    {
        $id = $this->holiday('2026-10-06');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-20', 'name' => 'Holiday-2'])->assertStatus(423);
        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['name' => 'Holiday-3'])->assertStatus(423);
        $this->deleteJson("/api/v1/attendance/holidays/{$id}")->assertStatus(423);
        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['date' => '2026-10-28', 'end_date' => '2026-10-28'])->assertStatus(423);   // the old date is locked
    }

    public function test_a_holiday_is_refused_when_any_groups_period_containing_it_is_locked(): void
    {
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true]);
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-24', 'name' => 'Holiday-1'])->assertStatus(423); // office October is open
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-28', 'name' => 'Holiday-2'])->assertCreated();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'office', 'period_start' => '2026-10-01', 'confirm_missing' => true]);
        $this->postJson('/api/v1/attendance/holidays', ['date' => '2026-10-29', 'name' => 'Holiday-3'])->assertStatus(423); // engineers' 10-26 period is open
    }
}
