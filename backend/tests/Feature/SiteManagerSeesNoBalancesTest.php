<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * A site manager files leave for their engineers and workers, and asks for advances and overtime, but reads no balance or other detail:
 * each request's status, dates and HR's reason only (owner, 2026-11-19). HR keeps every number.
 */
class SiteManagerSeesNoBalancesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private User $hr;

    private User $manager;

    private string $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->hr = $this->signIn('hr');
        $this->manager = $this->makeUser('manager');
        $this->engineer = $this->employee('engineer', '2020-01-01');
        app(EmployeeService::class)->assignManager($this->engineer, ['manager_id' => $this->manager->id, 'effective_from' => '2026-01-01']);
    }

    private function preview(): TestResponse
    {
        return $this->getJson("/api/v1/leave/requests/preview?employee_id={$this->engineer}&kind=annual&from_date=2026-11-01&to_date=2026-11-03");
    }

    public function test_the_preview_counts_the_days_and_shows_a_manager_no_balance(): void
    {
        $hr = $this->preview()->assertOk()->json('data');
        $this->assertArrayHasKey('remaining_after', $hr);
        $this->assertArrayHasKey('balance', $hr);

        $this->actingAs($this->manager, 'api');
        $mine = $this->preview()->assertOk()->json('data');
        $this->assertSame(['dates', 'days'], array_keys($mine));
        $this->assertSame($hr['days'], $mine['days']);
    }

    public function test_a_manager_files_leave_and_reads_its_status_and_hrs_reason_never_the_approval_preview(): void
    {
        $this->actingAs($this->manager, 'api');
        $id = $this->postJson('/api/v1/leave/requests', ['employee_id' => $this->engineer, 'kind' => 'annual', 'from_date' => '2026-11-01', 'to_date' => '2026-11-03'])
            ->assertCreated()->json('data.id');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approval', null)
            ->assertJsonPath('data.from_date', '2026-11-01');

        $this->actingAs($this->hr, 'api');
        $this->assertNotNull($this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->json('data.approval.balance'));   // HR still has it
        $this->postJson("/api/v1/leave/requests/{$id}/refuse", ['version' => 1, 'reason' => 'ضغط شغل في الموقع'])->assertOk();

        $this->actingAs($this->manager, 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'refused')
            ->assertJsonPath('data.decision_reason', 'ضغط شغل في الموقع')->assertJsonPath('data.approval', null);
        $this->getJson('/api/v1/leave/requests')->assertOk()->assertJsonPath('data.0.status', 'refused')->assertJsonMissingPath('data.0.balance');
    }

    public function test_a_site_manager_reads_no_balance_of_their_own_or_their_teams(): void
    {
        $self = $this->employee('office', '2020-01-01', ['user_id' => $this->manager->id]);
        $this->actingAs($this->manager, 'api');
        $this->getJson("/api/v1/employees/{$self}/leave")->assertNotFound();   // a site manager never finds their own record
        $this->getJson("/api/v1/employees/{$this->engineer}/leave")->assertForbidden();
        $this->getJson('/api/v1/leave/balances')->assertForbidden();
    }

    /** The request's kind on the first days and unpaid on the rest would give back the balance (Youssef, 2026-11-19; review of #74). */
    public function test_the_site_day_shows_a_manager_no_leave_day_type_an_approved_request_wrote(): void
    {
        $site = $this->workSite('Site-1');
        DB::table('manager_sites')->insert(['manager_id' => $this->manager->id, 'site_id' => $site]);
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        app(EmployeeService::class)->assignManager($e, ['manager_id' => $this->manager->id, 'effective_from' => '2026-07-01']);
        $types = $this->leaveTypes();
        foreach (['2026-10-01', '2026-10-03', '2026-10-04'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $types['annual']]);
        }
        $id = $this->postJson('/api/v1/leave/requests', ['employee_id' => $e, 'kind' => 'annual', 'from_date' => '2026-11-02', 'to_date' => '2026-11-05'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/leave/requests/{$id}/approve", ['version' => 1])->assertOk();   // 2 annual, 2 unpaid
        $this->assertSame($types['unpaid'], DB::table('attendance_days')->where(['employee_id' => $e, 'date' => '2026-11-05'])->value('site_id'));

        $this->cairo('2026-11-05 12:00');
        $this->actingAs($this->manager, 'api');
        foreach (['2026-11-02', '2026-11-05'] as $date) {
            $r = $this->getJson("/api/v1/attendance/site-day?site_id={$site}&date={$date}")->assertOk();
            $row = collect($r->json('data.rows'))->firstWhere('employee.id', $e);
            $this->assertSame('stored', $row['day']['state']);
            $this->assertNull($row['day']['leave_code'], $date);
            $this->assertNull($row['day']['site_id'], $date);
            $this->assertStringNotContainsString('unpaid', (string) $r->getContent());
            $this->assertStringNotContainsString($types['unpaid'], (string) $r->getContent());
            $this->assertStringNotContainsString($types['annual'], (string) $r->getContent());
        }
    }

    /** HR's note to the CEO is not the manager's to read: only a refusal's or a cancellation's reason is (Youssef, 2026-11-19). */
    public function test_a_manager_never_reads_hrs_escalation_note_while_escalated_or_after_approval(): void
    {
        $this->actingAs($this->manager, 'api');
        $id = $this->postJson('/api/v1/leave/requests', ['employee_id' => $this->engineer, 'kind' => 'annual', 'from_date' => '2026-11-01', 'to_date' => '2026-11-03'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/leave/requests/{$id}/escalate", ['version' => 1, 'reason' => 'رصيده لا يكفي'])->assertOk();

        $this->actingAs($this->manager, 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'escalated')->assertJsonPath('data.decision_reason', null);

        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->postJson("/api/v1/leave/requests/{$id}/approve", ['version' => 2])->assertOk();
        $this->actingAs($this->manager, 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decision_reason', null);
        $this->actingAs($this->hr, 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.decision_reason', 'رصيده لا يكفي');   // HR and the CEO still read it
    }
}
