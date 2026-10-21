<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->patchJson("/api/v1/attendance/holidays/{$id}", ['date' => '2026-10-28'])->assertStatus(423);   // the old date is locked
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
