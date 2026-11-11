<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Saving a termination date waits until the employee's open leave requests and wage proposals are decided (owner, 2026-11-11). */
class TerminationOpenItemsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite();
    }

    private function request(string $employeeId, string $from = '2026-11-02', string $to = '2026-11-03'): string
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $employeeId, 'kind' => 'annual', 'from_date' => $from, 'to_date' => $to])
            ->assertCreated()->json('data.id');
    }

    private function decide(string $id, string $action): TestResponse
    {
        return $this->postJson("/api/v1/leave/requests/{$id}/{$action}", ['version' => DB::table('leave_requests')->where('id', $id)->value('version')]);
    }

    private function terminate(string $employeeId, ?string $date = '2026-10-25'): TestResponse
    {
        return $this->patchJson("/api/v1/employees/{$employeeId}", ['termination_date' => $date]);
    }

    public function test_a_termination_date_is_refused_while_a_leave_request_is_pending_and_the_refusal_lists_it(): void
    {
        $e = $this->employee('engineer');
        $id = $this->request($e);

        $this->terminate($e)->assertStatus(409)->assertJsonPath('error.code', 'employee_has_open_items')
            ->assertJsonPath('error.items', [['kind' => 'leave_request', 'id' => $id, 'status' => 'pending', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03']]);
        $this->assertNull(Employee::whereKey($e)->value('termination_date'));
        $this->assertFalse(DB::table('audit_log')->where(['entity_id' => $e, 'event' => 'UPDATED'])->exists());
    }

    public function test_an_escalated_request_also_blocks_it(): void
    {
        $e = $this->employee('engineer');
        $id = $this->request($e);
        $this->decide($id, 'escalate')->assertOk();

        $this->terminate($e)->assertStatus(409)->assertJsonPath('error.items.0.status', 'escalated');
    }

    public function test_a_pending_wage_proposal_blocks_it(): void
    {
        $laborer = app(DayCrew::class)->propose(['name_ar' => 'Laborer-P', 'gender' => 'male'], $this->site, '2026-10-20', '350');

        $this->terminate($laborer['id'])->assertStatus(409)->assertJsonPath('error.code', 'employee_has_open_items')
            ->assertJsonPath('error.items', [['kind' => 'wage_proposal', 'id' => $laborer['proposal_id']]]);
    }

    public function test_the_date_saves_once_the_items_are_decided(): void
    {
        $e = $this->employee('engineer');
        $this->decide($this->request($e), 'approve')->assertOk();
        $this->terminate($e)->assertOk()->assertJsonPath('data.termination_date', '2026-10-25');

        $laborer = app(DayCrew::class)->propose(['name_ar' => 'Laborer-P', 'gender' => 'male'], $this->site, '2026-10-20', '350');
        $this->postJson("/api/v1/employees/wage-proposals/{$laborer['proposal_id']}/approve", ['version' => 1])->assertOk();
        $this->terminate($laborer['id'])->assertOk();
    }

    public function test_clearing_a_termination_date_is_never_refused(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-11-30']);
        $this->request($e);

        $this->terminate($e, null)->assertOk()->assertJsonPath('data.termination_date', null);
    }

    public function test_other_fields_save_while_items_are_open(): void
    {
        $e = $this->employee('engineer');
        $this->request($e);

        $this->patchJson("/api/v1/employees/{$e}", ['name_ar' => 'Engineer-X'])->assertOk()->assertJsonPath('data.name_ar', 'Engineer-X');
    }

    public function test_the_profile_forms_full_body_saves_while_items_are_open_when_a_future_termination_date_is_unchanged(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-11-30']);
        $this->request($e);
        // The profile form sends every identity field on each save, the termination date included (frontend/src/pages/employees.tsx, IDENTITY).
        $shown = $this->getJson("/api/v1/employees/{$e}")->json('data');
        $body = [];
        foreach (['name_ar', 'name_en', 'fingerprint_name', 'gender', 'national_id', 'insurance_no', 'phone', 'address', 'department', 'residence_id',
            'pay_method', 'bank_account', 'hire_date', 'contract_end_date', 'termination_date', 'termination_reason', 'hr_notes'] as $k) {
            $body[$k] = $shown[$k] ?? null;
        }

        $this->patchJson("/api/v1/employees/{$e}", [...$body, 'name_ar' => 'Engineer-X'])->assertOk()
            ->assertJsonPath('data.name_ar', 'Engineer-X')->assertJsonPath('data.termination_date', '2026-11-30');
    }
}
