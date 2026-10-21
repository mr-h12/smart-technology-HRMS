<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ManagerScopeTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $name): string
    {
        return $this->postJson('/api/v1/employees', ['name_ar' => $name, 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-01-01', 'salary' => '9000'])->json('data.id');
    }

    private function assign(string $emp, User $m, string $from): TestResponse
    {
        return $this->postJson("/api/v1/employees/$emp/manager-assignments", ['manager_id' => $m->id, 'effective_from' => $from]);
    }

    public function test_manager_sees_only_current_employees(): void
    {
        $this->travelTo('2026-10-17 09:00:00');
        [$a, $b] = [$this->makeUser('manager'), $this->makeUser('manager')];
        $this->signIn('hr');
        [$e1, $e2, $e3] = [$this->employee('أ'), $this->employee('ب'), $this->employee('ج')];
        $this->assign($e1, $a, '2026-10-01')->assertCreated()->assertJsonPath('data.current_manager.id', $a->id);
        $this->assign($e2, $a, '2026-10-01');
        $this->assign($e3, $b, '2026-10-01');
        $this->assign($e3, $b, '2026-10-01')->assertStatus(422);              // duplicate date
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'employee_manager_assignments', 'event' => 'CREATED']);

        $this->actingAs($a, 'api');
        $this->getJson('/api/v1/employees')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/employees/summary')->assertJsonPath('data.total', 2)->assertJsonPath('data.engineer', 2);
        $this->getJson("/api/v1/employees/$e1")->assertOk();
        $this->getJson("/api/v1/employees/$e3")->assertNotFound();
        $this->getJson('/api/v1/employees/'.Str::uuid7())->assertNotFound();
        $this->getJson("/api/v1/employees/$e3/fare?site_id=".Str::uuid7())->assertNotFound();
        $this->assign($e1, $b, '2026-11-01')->assertStatus(403);              // managers cannot reassign
    }

    public function test_managers_never_receive_pay_identity_or_hr_notes(): void
    {
        $this->travelTo('2026-10-17 09:00:00');
        $a = $this->makeUser('manager');
        $this->signIn('hr');
        $e = $this->postJson('/api/v1/employees', [
            'name_ar' => 'أ', 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-01-01', 'salary' => '9000',
            'national_id' => '29001011234567', 'insurance_no' => '123', 'pay_method' => 'bank', 'bank_account' => 'EG01', 'hr_notes' => 'سري',
        ])->assertCreated()->json('data.id');
        $this->assign($e, $a, '2026-10-01');
        $hidden = ['national_id', 'insurance_no', 'bank_account', 'hr_notes'];

        $hr = $this->getJson("/api/v1/employees/$e")->assertOk()->json('data');
        $hrList = $this->getJson('/api/v1/employees')->json('data.0');
        $this->assertNotNull($hr['current_pay']['salary']);
        $this->assertNotEmpty($hr['pay_changes']);
        foreach ($hidden as $key) {
            $this->assertNotNull($hr[$key], $key);
            $this->assertNotNull($hrList[$key], $key);
        }

        $this->actingAs($a, 'api');
        $list = $this->getJson('/api/v1/employees')->assertJsonCount(1, 'data')->json('data.0');
        $show = $this->getJson("/api/v1/employees/$e")->assertOk()->json('data');
        foreach ([...$hidden, 'current_pay', 'pay_changes'] as $key) {
            $this->assertArrayNotHasKey($key, $list, "list: $key");
            $this->assertArrayNotHasKey($key, $show, "show: $key");
        }
        $this->getJson('/api/v1/employees?search=2900101')->assertJsonCount(0, 'data');   // no inferring the ID by search
        $this->getJson('/api/v1/employees?search=أ')->assertJsonCount(1, 'data');
    }

    public function test_reassignment_follows_the_cairo_date(): void
    {
        $this->travelTo('2026-10-17 12:00:00');
        [$a, $b] = [$this->makeUser('manager'), $this->makeUser('manager')];
        $this->signIn('hr');
        $e = $this->employee('أ');
        $this->assign($e, $a, '2026-10-01');
        $this->assign($e, $b, '2026-10-18');
        $this->actingAs($a, 'api');
        $this->getJson("/api/v1/employees/$e")->assertOk();
        $this->travelTo('2026-10-17 21:30:00');                               // 2026-10-18 00:30 in Cairo
        $this->getJson("/api/v1/employees/$e")->assertNotFound();
        $this->actingAs($b, 'api');
        $this->getJson("/api/v1/employees/$e")->assertOk()->assertJsonPath('data.current_manager.id', $b->id);
    }

    public function test_only_active_users_can_be_assigned(): void
    {
        $gone = $this->makeUser('manager', ['is_active' => false]);
        $this->signIn('hr');
        $this->assign($this->employee('أ'), $gone, '2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'manager_id');
    }

    public function test_site_managers_scope_the_site_list(): void
    {
        $a = $this->makeUser('manager');
        $this->signIn('hr');
        $mine = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->json('data.id');
        $other = $this->postJson('/api/v1/sites', ['name_ar' => 'العامرية', 'kind' => 'work'])->json('data.id');
        $leave = $this->postJson('/api/v1/sites', ['name_ar' => 'اجازة الجمعة', 'kind' => 'leave', 'leave_code' => 'friday'])->assertCreated()->json('data.id');
        $gone = $this->makeUser('manager', ['is_active' => false]);
        $this->putJson("/api/v1/sites/$mine/managers", ['manager_ids' => [$gone->id]])->assertStatus(422);
        $this->putJson("/api/v1/sites/$mine/managers", ['manager_ids' => [$a->id]])->assertOk()->assertJsonPath('data.managers.0.id', $a->id);
        $row = DB::table('audit_log')->where(['event' => 'SITE_MANAGERS_UPDATED', 'entity_id' => $mine])->first();
        $this->assertSame(['manager_ids' => []], json_decode($row->old_values, true));

        $this->actingAs($a, 'api');
        $names = collect($this->getJson('/api/v1/sites')->json('data'))->pluck('name_ar')->sort()->values()->all();
        $this->assertSame(['اجازة الجمعة', 'اجازة السبت', 'سموحة'], $names);   // leave types (the Saturday migration's too) are visible to every manager
        $this->getJson("/api/v1/sites/$mine")->assertOk();
        $this->getJson("/api/v1/sites/$leave")->assertOk();
        $this->getJson("/api/v1/sites/$other")->assertNotFound();
        $this->putJson("/api/v1/sites/$mine/managers", ['manager_ids' => []])->assertStatus(403);
    }
}
