<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * #79: a site manager proposes a new daily wage for a day laborer who already exists, and HR decides it from a date it picks
 * (Youssef, 2026-11-19). Synthetic names and numbers only.
 */
class WageChangeProposalTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s3;

    private User $m;

    private User $hr;

    private string $laborer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->leaveTypes();
        $this->hr = $this->signIn('hr');
        [$this->s1, $this->s3] = [$this->workSite('Site-1'), $this->workSite('Site-3')];
        $this->m = $this->managerOf($this->s1);
        $this->managerOf($this->s3);
        $this->laborer = $this->employee('day_laborer');   // daily wage 330 from 2026-01-01
        $this->homeSite($this->laborer, $this->s1, '2026-01-01');
    }

    public function test_a_site_manager_proposes_a_wage_for_a_laborer_in_reach_and_hr_hears_at_once_without_the_amount(): void
    {
        $this->actingAs($this->m, 'api');
        $id = $this->propose(['reason' => 'يعمل منذ سنة'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $row = DB::table('employee_wage_proposals')->where('id', $id)->first();
        $this->assertSame('wage_change', $row->kind);
        $this->assertSame('يعمل منذ سنة', $row->reason);
        $this->assertNull($row->effective_from);
        $notes = DB::table('notifications')->where('user_id', $this->hr->id)->where('type', 'wage_proposed')->get();
        $this->assertCount(1, $notes);
        $this->assertStringNotContainsString('400', (string) $notes[0]->data);
        $this->assertStringContainsString('Site-1', (string) $notes[0]->data);
        $this->assertTrue(DB::table('audit_log')->where('entity_id', $id)->exists());
    }

    public function test_a_laborer_out_of_reach_is_a_404(): void
    {
        $theirs = $this->employee('day_laborer');
        $this->homeSite($theirs, $this->s3, '2026-01-01');
        $this->actingAs($this->m, 'api');

        $this->propose([], $theirs)->assertNotFound();
    }

    public function test_an_archived_laborer_is_out_of_reach(): void
    {
        $gone = $this->employee('day_laborer', '2026-01-01', ['termination_date' => '2026-10-01']);
        $this->homeSite($gone, $this->s1, '2026-01-01');
        $this->actingAs($this->m, 'api');

        $this->propose([], $gone)->assertNotFound();
    }

    public function test_someone_who_is_not_a_day_laborer_is_refused(): void
    {
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-01-01');
        $this->actingAs($this->m, 'api');

        $this->propose([], $worker)->assertStatus(422)->assertJsonPath('error.details.0.field', 'employee')
            ->assertJsonPath('error.details.0.message', __('errors.not_a_day_laborer'));
        $this->assertSame(0, DB::table('employee_wage_proposals')->count());
    }

    public function test_a_caller_without_the_permission_is_refused(): void
    {
        $this->propose()->assertForbidden();   // HR holds employee.approve_wage, not employee.propose_wage
        DB::table('role_permissions')->where('role_id', $this->m->role_id)
            ->whereIn('permission_id', DB::table('permissions')->where(['resource' => 'employee', 'action' => 'propose_wage'])->pluck('id'))->delete();
        $this->actingAs($this->m->fresh(), 'api');
        $this->propose()->assertForbidden();
    }

    public function test_the_wage_must_be_money_above_zero(): void
    {
        $this->actingAs($this->m, 'api');
        foreach (['0', '-5', 'abc', '1.1234567', ''] as $bad) {
            $this->propose(['daily_wage' => $bad])->assertStatus(422)->assertJsonPath('error.details.0.field', 'daily_wage');
        }
    }

    public function test_a_second_pending_proposal_for_the_same_laborer_is_refused_with_a_clear_message(): void
    {
        $this->actingAs($this->m, 'api');
        $this->propose()->assertCreated();

        $this->propose(['daily_wage' => '410'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'employee')
            ->assertJsonPath('error.details.0.message', __('errors.wage_proposal_pending'));
        $this->assertSame(1, DB::table('employee_wage_proposals')->count());
    }

    public function test_a_retry_with_the_same_request_id_returns_the_first_answer_and_a_changed_one_is_refused(): void
    {
        $this->actingAs($this->m, 'api');
        $rid = (string) Str::uuid();
        $first = $this->propose(['client_request_id' => $rid])->assertCreated()->json('data.id');

        $this->propose(['client_request_id' => $rid, 'daily_wage' => '٤٠٠'])->assertSuccessful()->assertJsonPath('data.id', $first);
        $this->propose(['client_request_id' => $rid, 'daily_wage' => '450'])->assertStatus(409)->assertJsonPath('error.code', 'client_request_reused');
        $this->assertSame(1, DB::table('employee_wage_proposals')->count());
    }

    public function test_hr_sees_the_kind_the_current_wage_the_reason_and_the_site(): void
    {
        $this->actingAs($this->m, 'api');
        $this->propose(['reason' => 'سبب المدير']);
        $this->actingAs($this->hr, 'api');

        $this->getJson('/api/v1/employees/wage-proposals')->assertOk()
            ->assertJsonPath('data.0.kind', 'wage_change')
            ->assertJsonPath('data.0.current_wage', '330.000000')
            ->assertJsonPath('data.0.daily_wage', '400.000000')
            ->assertJsonPath('data.0.reason', 'سبب المدير')
            ->assertJsonPath('data.0.site.name_ar', 'Site-1');
    }

    public function test_approving_needs_a_date_and_writes_the_pay_row_from_it(): void
    {
        $p = $this->proposed();

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1])->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-16'])->assertOk();

        $pays = DB::table('employee_pay_changes')->where('employee_id', $this->laborer)->whereNull('deleted_at')->orderBy('effective_from')->get(['effective_from', 'daily_wage']);
        $this->assertSame([['2026-01-01', '330.000000'], ['2026-10-16', '400.000000']], $pays->map(fn ($r) => [(string) $r->effective_from, (string) $r->daily_wage])->all());
        $this->assertSame('2026-10-16', (string) DB::table('employee_wage_proposals')->where('id', $p)->value('effective_from'));
    }

    public function test_approval_and_refusal_are_audited_and_so_is_the_pay_row(): void
    {
        $p = $this->proposed();
        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-16'])->assertOk();
        $pay = (string) DB::table('employee_pay_changes')->where(['employee_id' => $this->laborer, 'effective_from' => '2026-10-16'])->value('id');
        $this->assertTrue(DB::table('audit_log')->where(['entity_id' => $p, 'event' => 'UPDATED'])->where('new_values->status', 'approved')->exists());
        $this->assertTrue(DB::table('audit_log')->where('entity_id', $pay)->exists());

        $q = $this->proposed();
        $this->postJson("/api/v1/employees/wage-proposals/{$q}/refuse", ['version' => 1, 'reason' => 'ليس الآن'])->assertOk();
        $this->assertTrue(DB::table('audit_log')->where(['entity_id' => $q, 'event' => 'UPDATED'])->where('new_values->status', 'refused')->exists());
    }

    public function test_approval_is_refused_when_the_laborer_left_before_the_date(): void
    {
        $p = $this->proposed();
        DB::table('employees')->where('id', $this->laborer)->update(['termination_date' => '2026-10-20']);

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-22'])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from')->assertJsonPath('error.details.0.message', __('errors.not_a_day_laborer_on_date'));
        $this->assertSame('pending', DB::table('employee_wage_proposals')->where('id', $p)->value('status'));
    }

    public function test_hr_may_approve_a_revised_wage(): void
    {
        $p = $this->proposed();

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-22', 'daily_wage' => '380'])->assertOk();

        $this->assertSame('380.000000', (string) DB::table('employee_pay_changes')->where(['employee_id' => $this->laborer, 'effective_from' => '2026-10-22'])->value('daily_wage'));
    }

    public function test_a_date_inside_a_locked_period_is_refused_and_the_proposal_stays_pending(): void
    {
        $p = $this->proposed();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-10-01', 'confirm_missing' => true])->assertCreated();

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-05'])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from')->assertJsonPath('error.details.0.message', __('errors.dated_change_period_locked'));

        $this->assertSame('pending', DB::table('employee_wage_proposals')->where('id', $p)->value('status'));
        $this->assertSame(1, DB::table('employee_pay_changes')->where('employee_id', $this->laborer)->count());
    }

    public function test_a_date_that_already_has_a_pay_row_is_refused(): void
    {
        $p = $this->proposed();

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-01-01'])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from')->assertJsonPath('error.details.0.message', __('errors.duplicate_date'));
        $this->assertSame('pending', DB::table('employee_wage_proposals')->where('id', $p)->value('status'));
    }

    public function test_refusing_keeps_the_laborer_employed_on_the_same_site_at_the_same_wage(): void
    {
        $p = $this->proposed();

        $this->postJson("/api/v1/employees/wage-proposals/{$p}/refuse", ['version' => 1, 'reason' => 'ليس الآن'])->assertOk();

        $this->assertNull(DB::table('employees')->where('id', $this->laborer)->value('termination_date'));
        $this->assertSame(1, DB::table('employee_pay_changes')->where('employee_id', $this->laborer)->count());
        $this->assertSame(0, DB::table('day_crew_changes')->where('employee_id', $this->laborer)->count());
        $this->actingAs($this->m, 'api');
        $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}&date=2026-10-22")->assertOk()->assertSee($this->laborer);
    }

    public function test_the_manager_is_told_the_outcome_and_reads_it_on_their_request_but_never_in_the_list(): void
    {
        $p = $this->proposed();
        $this->postJson("/api/v1/employees/wage-proposals/{$p}/approve", ['version' => 1, 'effective_from' => '2026-10-16'])->assertOk();
        $note = DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'wage_approved'])->first();
        $this->assertNotNull($note);
        $this->assertStringNotContainsString('400', (string) $note->data);

        $this->actingAs($this->m, 'api');
        $list = $this->getJson('/api/v1/employees/wage-proposals/mine')->assertOk()->assertJsonPath('data.0.kind', 'wage_change');
        $this->assertStringNotContainsString('400', $list->getContent());
        $this->assertStringNotContainsString('330', $list->getContent());
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$p}")->assertOk()
            ->assertJsonPath('data.approved_wage', '400.000000')->assertJsonPath('data.effective_from', '2026-10-16')->assertJsonPath('data.reason', 'سبب');
        $this->assertStringNotContainsString('330', $this->getJson("/api/v1/employees/wage-proposals/mine/{$p}")->getContent());   // never the current wage
    }

    public function test_a_refused_wage_change_tells_the_manager(): void
    {
        $p = $this->proposed();
        $this->postJson("/api/v1/employees/wage-proposals/{$p}/refuse", ['version' => 1, 'reason' => 'ليس الآن'])->assertOk();

        $this->assertTrue(DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'wage_refused'])->exists());
    }

    public function test_a_new_laborer_proposal_is_still_approved_from_the_hire_date_without_a_date(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد أول', 'gender' => 'male', 'daily_wage' => '350'])->assertCreated()->json('data');
        $this->actingAs($this->hr, 'api');

        $this->getJson('/api/v1/employees/wage-proposals')->assertJsonPath('data.0.kind', 'new_laborer')->assertJsonPath('data.0.current_wage', null);
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 1])->assertOk();
        $this->assertSame('2026-10-22', (string) DB::table('employee_pay_changes')->where('employee_id', $made['id'])->value('effective_from'));
    }

    /** The site manager's proposal of 400 for the laborer, with a reason; back as HR. */
    private function proposed(): string
    {
        $this->actingAs($this->m, 'api');
        $id = $this->propose(['reason' => 'سبب'])->assertCreated()->json('data.id');
        $this->actingAs($this->hr, 'api');

        return $id;
    }

    private function propose(array $over = [], ?string $employee = null): TestResponse
    {
        return $this->postJson('/api/v1/employees/'.($employee ?? $this->laborer).'/wage-proposals', ['daily_wage' => '400', ...$over]);
    }
}
