<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** An archived employee is in no one's reach, whatever the date (owner, 2026-11-11). */
class ArchivedReachTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private User $hr;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->hr = $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite();
    }

    private function archive(string $employeeId): void
    {
        $this->actingAs($this->hr, 'api')->patchJson("/api/v1/employees/{$employeeId}", ['termination_date' => '2026-10-19'])->assertOk();
    }

    private function assignManager(string $employeeId, User $manager): void
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);
    }

    public function test_a_site_manager_reaches_nothing_of_an_employee_once_archived(): void
    {
        $m = $this->managerOf($this->site);
        $eng = $this->employee('engineer');
        $this->assignManager($eng, $m);
        $w = $this->employee('permanent_worker');
        $this->homeSite($w, $this->site, '2026-01-01');

        $this->actingAs($m, 'api');
        $this->getJson("/api/v1/employees/{$eng}")->assertOk();
        $this->getJson("/api/v1/employees/{$w}")->assertOk();

        $this->archive($eng);
        $this->archive($w);

        $this->actingAs($m, 'api');
        foreach ([$eng, $w] as $id) {
            $this->getJson("/api/v1/employees/{$id}")->assertNotFound();
            $this->getJson("/api/v1/employees/{$id}/fare")->assertNotFound();
            $this->getJson("/api/v1/employees/{$id}/leave")->assertNotFound();
        }
        $this->getJson('/api/v1/employees?archived=true')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/employees/summary')->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/attendance/employees/{$eng}/period")->assertForbidden();   // the seeded site manager holds no attendance.view
    }

    public function test_an_own_scope_attendance_holder_gets_404_for_an_archived_employee_they_used_to_reach(): void
    {
        $lead = $this->userWith('own', 'own');
        $eng = $this->employee('engineer');
        $this->assignManager($eng, $lead);

        $this->actingAs($lead, 'api')->getJson("/api/v1/attendance/employees/{$eng}/period")->assertOk();

        $this->archive($eng);

        $this->actingAs($lead, 'api');
        $this->getJson("/api/v1/attendance/employees/{$eng}/period")->assertNotFound();
        $this->getJson("/api/v1/attendance/days/{$eng}/2026-10-19")->assertNotFound();
        // not employee_archived: that would confirm the id is a former employee
        $this->putDay($eng, '2026-10-19', ['site_id' => $this->site])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->postJson('/api/v1/attendance/days/bulk', ['date' => '2026-10-19', 'rows' => [['employee_id' => $eng, 'site_id' => $this->site]]])
            ->assertNotFound();
    }

    public function test_a_site_manager_loses_the_requests_and_the_name_in_old_notifications_of_an_archived_employee(): void
    {
        $m = $this->managerOf($this->site);
        $eng = $this->employee('engineer');
        $this->assignManager($eng, $m);

        $id = $this->actingAs($m, 'api')->postJson('/api/v1/leave/requests', ['employee_id' => $eng, 'kind' => 'annual', 'from_date' => '2026-10-12', 'to_date' => '2026-10-13'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->hr, 'api')->postJson("/api/v1/leave/requests/{$id}/approve", ['version' => DB::table('leave_requests')->where('id', $id)->value('version')])
            ->assertOk();
        $this->actingAs($m, 'api')->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.employee.id', $eng);

        $this->archive($eng);

        $this->actingAs($m, 'api');
        $this->getJson('/api/v1/leave/requests')->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/v1/leave/requests/{$id}")->assertNotFound();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.employee', null);
    }
}
