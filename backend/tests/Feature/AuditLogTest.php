<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_writes_are_audited_with_actor_request_and_values(): void
    {
        $hr = $this->signIn('hr');
        $res = $this->postJson('/api/v1/employees', ['name_ar' => 'أحمد علي', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000']);
        $id = $res->json('data.id');
        $row = DB::table('audit_log')->where(['entity_type' => 'employees', 'entity_id' => $id])->first();
        $this->assertSame('CREATED', $row->event);
        $this->assertSame($hr->id, $row->user_id);
        $this->assertSame($res->headers->get('X-Request-Id'), $row->request_id);
        $this->assertSame('أحمد علي', json_decode($row->new_values, true)['name_ar']);
        $this->assertStringContainsString('أحمد', $row->new_values); // stored unescaped
        $this->assertSame('9000', json_decode(DB::table('audit_log')->where('entity_type', 'employee_pay_changes')->value('new_values'), true)['salary']);
        $this->assertSame($hr->id, DB::table('employees')->where('id', $id)->value('created_by'));

        $dev = $this->signIn('developer');
        $this->patchJson("/api/v1/employees/$id", ['name_ar' => 'أحمد محمد'])->assertOk();
        $upd = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->first();
        $this->assertSame(['name_ar' => 'أحمد علي'], json_decode($upd->old_values, true));
        $this->assertSame(['name_ar' => 'أحمد محمد'], json_decode($upd->new_values, true));
        $this->assertSame($dev->id, DB::table('employees')->where('id', $id)->value('updated_by'));

        $this->deleteJson("/api/v1/employees/$id")->assertNoContent();
        $this->assertDatabaseHas('audit_log', ['entity_id' => $id, 'event' => 'DELETED']);
    }

    public function test_site_writes_are_audited(): void
    {
        $this->signIn('hr');
        $id = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->json('data.id');
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'sites', 'entity_id' => $id, 'event' => 'CREATED']);
    }

    public function test_a_failed_audit_insert_rolls_the_write_back(): void
    {
        $this->signIn('hr');
        $id = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->json('data.id');
        request()->attributes->set('request_id', str_repeat('x', 65)); // too long for varchar(64)
        try {
            Site::findOrFail($id)->update(['name_ar' => 'العجمي']);
            $this->fail('the audit insert should have failed');
        } catch (QueryException) {
        }
        $this->assertSame('سموحة', DB::table('sites')->where('id', $id)->value('name_ar'));
    }

    public function test_audit_log_is_append_only(): void
    {
        AuditLog::record('TEST', 'employees', null);
        foreach (['update audit_log set event = \'X\'', 'delete from audit_log', 'truncate audit_log'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));   // savepoint: one refusal must not abort the next statement
                $this->fail("$sql succeeded");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('audit_log')->count());
    }

    public function test_login_events(): void
    {
        $u = $this->makeUser('hr', ['email' => 'hr@smart.test']);
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'hr@smart.test', 'password' => 'nope']);
        }
        $this->assertSame(5, DB::table('audit_log')->where(['event' => 'LOGIN_FAILED', 'entity_id' => $u->id])->count());
        $this->assertDatabaseHas('audit_log', ['event' => 'ACCOUNT_LOCKED', 'entity_id' => $u->id]);
        $this->postJson('/api/v1/auth/login', ['email' => 'hr@smart.test', 'password' => 'nope'])->assertStatus(423);
        $this->assertSame(['reason' => 'account_locked'], json_decode(DB::table('audit_log')->where(['event' => 'LOGIN_BLOCKED', 'entity_id' => $u->id])->value('new_values'), true));
        $this->postJson('/api/v1/auth/login', ['email' => 'ghost@smart.test', 'password' => 'x']);
        $this->assertSame(['email' => 'ghost@smart.test'], json_decode(DB::table('audit_log')->whereNull('entity_id')->where('event', 'LOGIN_FAILED')->value('new_values'), true));

        $v = $this->makeUser('ceo', ['email' => 'ceo@smart.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ceo@smart.test', 'password' => 'secret123'])->json('data.token');
        $this->assertDatabaseHas('audit_log', ['event' => 'LOGIN_SUCCEEDED', 'user_id' => $v->id, 'entity_id' => $v->id]);
        $this->withToken($token)->postJson('/api/v1/auth/logout');
        $this->assertDatabaseHas('audit_log', ['event' => 'LOGOUT', 'entity_id' => $v->id]);
    }

    public function test_audit_endpoint_filters_by_actor_type_and_cairo_dates(): void
    {
        $this->travelTo('2026-10-16 22:30:00');               // 2026-10-17 01:30 in Cairo
        $hr = $this->signIn('hr');
        $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work']);
        $this->signIn('ceo');
        $this->getJson("/api/v1/audit-log?user_id={$hr->id}&entity_type=sites&from=2026-10-17&to=2026-10-17")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $hr->id);
        $this->getJson('/api/v1/audit-log?from=2026-10-16&to=2026-10-16')->assertJsonCount(0, 'data');
        $this->signIn('manager');
        $this->getJson('/api/v1/audit-log')->assertStatus(403);
    }

    public function test_list_returns_each_entry_id_as_its_uuid(): void
    {
        $this->signIn('developer');
        $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->assertCreated();
        $this->postJson('/api/v1/sites', ['name_ar' => 'العامرية', 'kind' => 'work'])->assertCreated();

        $ids = $this->getJson('/api/v1/audit-log')->assertOk()->json('data.*.id');

        $this->assertGreaterThanOrEqual(2, count($ids));
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertEqualsCanonicalizing(DB::table('audit_log')->pluck('id')->all(), $ids);
        foreach ($ids as $id) {
            $this->assertTrue(Str::isUuid($id), (string) $id);
        }
    }
}
