<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class SignoffsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    public function test_manager_submission_and_return_limit_only_the_managers_writes(): void
    {
        $this->cairo('2026-10-22 09:00');
        $hr = $this->signIn('hr');
        $this->leaveTypes();
        $site = $this->workSite();
        $manager = $this->managerOf($site);
        $employee = $this->employee('engineer');
        $other = $this->employee('engineer');
        $outside = $this->employee('engineer');
        foreach ([$employee, $other] as $id) {
            app(EmployeeService::class)->assignManager($id, ['manager_id' => $manager->id, 'effective_from' => '2026-10-01']);
        }
        $sign = ['site_id' => $site, 'employee_id' => $employee, 'employee_type' => 'engineer', 'period_start' => '2026-09-26', 'version' => null];
        $day = ['employee_id' => $employee, 'site_id' => $site, 'version' => null, 'in_time' => '09:00', 'out_time' => '16:00'];
        $this->actingAs($manager, 'api');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $site, 'date' => '2026-10-22', 'rows' => [$day]])->assertOk();
        $this->postJson('/api/v1/attendance/signoffs', $sign)->assertUnprocessable()->assertJsonPath('error.code', 'signoff_not_due');

        $this->cairo('2026-10-25 18:00');
        $this->postJson('/api/v1/attendance/signoffs', [...$sign, 'employee_id' => $outside])->assertNotFound();
        $submission = $this->postJson('/api/v1/attendance/signoffs', $sign)->assertCreated()->assertJsonPath('data.status', 'signed')->json('data.id');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $site, 'date' => '2026-10-25', 'rows' => [$day]])
            ->assertStatus(423)->assertJsonPath('error.code', 'period_signed');
        $this->postJson("/api/v1/attendance/signoffs/{$submission}/return", ['version' => 1, 'note' => 'Change checkout'])->assertForbidden();

        $this->actingAs($hr, 'api');
        $this->putDay($employee, '2026-10-24', ['site_id' => $site, 'in_time' => '09:00', 'out_time' => '16:00'])->assertOk();
        $this->getJson('/api/v1/attendance/grid?group=engineer&period_start=2026-09-26')->assertOk()->assertJsonFragment(['status' => 'signed']);
        $this->postJson("/api/v1/attendance/signoffs/{$submission}/return", ['version' => 1])->assertUnprocessable();
        $this->postJson("/api/v1/attendance/signoffs/{$submission}/return", ['version' => 1, 'note' => 'Change October 22 checkout'])
            ->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('notifications', ['user_id' => $manager->id, 'type' => 'attendance_period_returned']);

        $this->cairo('2026-10-27 14:00');
        $this->actingAs($manager, 'api');
        $this->getJson("/api/v1/attendance/site-period?site_id={$site}&which=previous")
            ->assertOk()->assertJsonFragment(['return_note' => 'Change October 22 checkout']);
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $site, 'date' => '2026-10-22', 'rows' => [[...$day, 'version' => 1, 'out_time' => '15:00']]])->assertOk();
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $site, 'date' => '2026-10-22', 'rows' => [[...$day, 'employee_id' => $other]]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'outside_manager_window');
        $this->postJson('/api/v1/attendance/signoffs', [...$sign, 'version' => 1])->assertConflict();
        $this->postJson('/api/v1/attendance/signoffs', [...$sign, 'version' => 2])->assertCreated()
            ->assertJsonPath('data.status', 'signed')->assertJsonPath('data.version', 3);

        $this->actingAs($hr, 'api');
        $this->putDay($other, '2026-10-22', ['site_id' => $site, 'in_time' => '09:00', 'out_time' => '16:00'])->assertOk();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])
            ->assertCreated()->assertJsonPath('data.is_locked', true);
        $this->postJson("/api/v1/attendance/signoffs/{$submission}/return", ['version' => 3, 'note' => 'Too late'])->assertStatus(423);
    }

    public function test_new_manager_cannot_see_days_before_assignment_or_previous_managers_return_note(): void
    {
        $this->cairo('2026-10-25 18:00');
        $hr = $this->signIn('hr');
        $this->leaveTypes();
        $site = $this->workSite();
        $previous = $this->managerOf($site);
        $current = $this->managerOf($site);
        $employee = $this->employee('engineer');
        $employees = app(EmployeeService::class);
        $employees->assignManager($employee, ['manager_id' => $previous->id, 'effective_from' => '2026-09-26']);
        $this->putDay($employee, '2026-10-10', ['site_id' => $site, 'in_time' => '09:00', 'out_time' => '16:00'])->assertOk();
        $this->actingAs($previous, 'api');
        $id = $this->postJson('/api/v1/attendance/signoffs', [
            'site_id' => $site, 'employee_id' => $employee, 'employee_type' => 'engineer',
            'period_start' => '2026-09-26', 'version' => null,
        ])->assertCreated()->json('data.id');

        $this->actingAs($hr, 'api');
        $this->postJson("/api/v1/attendance/signoffs/{$id}/return", ['version' => 1, 'note' => 'Private correction'])->assertOk();
        $employees->assignManager($employee, ['manager_id' => $current->id, 'effective_from' => '2026-10-24']);

        $this->actingAs($current, 'api')->getJson("/api/v1/attendance/site-period?site_id={$site}")
            ->assertOk()->assertJsonPath('data.0.marked', 0)
            ->assertJsonPath('data.0.signoff.return_note', null)->assertJsonMissing(['return_note' => 'Private correction']);
    }
}
