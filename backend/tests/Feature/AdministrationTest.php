<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])->json('data.token');
    }

    /** Status of GET /auth/me with $token, then back to acting as $actor. */
    private function probe(string $token, User $actor): int
    {
        $status = $this->withToken($token)->getJson('/api/v1/auth/me')->status();
        $this->flushHeaders();
        $this->actingAs($actor, 'api');

        return $status;
    }

    public function test_developer_creates_an_account_and_only_the_developer_can(): void
    {
        $this->signIn('developer');
        $res = $this->postJson('/api/v1/users', ['name' => 'منى', 'email' => 'mona@smart.test', 'password' => 'abcd1234', 'role_id' => Role::where('slug', 'manager')->value('id')])
            ->assertCreated()->assertJsonMissingPath('data.password');
        $new = DB::table('audit_log')->where(['event' => 'USER_CREATED', 'entity_id' => $res->json('data.id')])->value('new_values');
        $this->assertStringNotContainsString('password', $new);
        $this->signIn('hr');
        $this->postJson('/api/v1/users', [])->assertStatus(403);
        $this->getJson('/api/v1/users')->assertOk();                    // HR may list accounts to pick managers
    }

    public function test_create_normalises_email_and_rejects_case_duplicate(): void
    {
        $this->signIn('developer');
        $role = Role::where('slug', 'manager')->value('id');
        $this->postJson('/api/v1/users', ['name' => 'أ', 'email' => ' Ali@Smart.Test ', 'password' => 'abcd1234', 'role_id' => $role])
            ->assertCreated()->assertJsonPath('data.email', 'ali@smart.test');
        $this->postJson('/api/v1/users', ['name' => 'ب', 'email' => 'ALI@smart.test', 'password' => 'abcd1234', 'role_id' => $role])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'email');
        $this->postJson('/api/v1/users', ['name' => 'ج', 'email' => 'c@smart.test', 'password' => 'abcdefgh', 'role_id' => $role])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'password');
    }

    public function test_deactivate_and_password_set_end_sessions(): void
    {
        $m = $this->makeUser('manager');
        $t1 = $this->token($m);
        $dev = $this->signIn('developer');
        $this->patchJson("/api/v1/users/{$m->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(401, $this->probe($t1, $dev));
        $this->patchJson("/api/v1/users/{$m->id}/reactivate")->assertOk();
        $t2 = $this->token($m);
        $this->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->assertSame(401, $this->probe($t2, $dev));
        $this->assertDatabaseHas('audit_log', ['event' => 'PASSWORD_SET', 'entity_id' => $m->id, 'new_values' => null]);
    }

    public function test_password_set_unlocks_a_locked_account(): void
    {
        $m = $this->makeUser('manager', ['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(30)]);
        $this->signIn('developer');
        $this->putJson("/api/v1/users/{$m->id}/password", ['password' => []])->assertStatus(422);
        $this->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => $m->email, 'password' => 'newpass99'])->assertCreated();
    }

    public function test_sessions_are_listed_and_terminated(): void
    {
        $m = $this->makeUser('manager');
        $t = $this->token($m);
        $this->signIn('developer');
        $sid = $this->getJson("/api/v1/users/{$m->id}/sessions")->assertJsonCount(1, 'data')->json('data.0.id');
        $other = $this->makeUser('manager');
        $this->deleteJson("/api/v1/users/{$other->id}/sessions/$sid")->assertNotFound();
        $this->deleteJson("/api/v1/users/{$m->id}/sessions/$sid")->assertNoContent();
        $this->withToken($t)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertDatabaseHas('audit_log', ['event' => 'ADMIN_SESSION_TERMINATED', 'entity_id' => $m->id]);
    }

    public function test_role_matrix_is_editable_and_audited(): void
    {
        $this->signIn('developer');
        $this->getJson('/api/v1/permissions')->assertJsonCount(21, 'data');
        $role = $this->postJson('/api/v1/roles', ['slug' => 'auditor', 'name' => 'Auditor', 'name_ar' => 'مراجع'])->assertCreated()->json('data.id');
        $pid = Permission::where(['resource' => 'audit_log', 'action' => 'view', 'scope' => 'all'])->value('id');
        $this->patchJson("/api/v1/roles/$role/permissions", ['permission_ids' => [$pid]])->assertOk()->assertJsonPath('data.permissions', ['audit_log.view.all']);
        $row = DB::table('audit_log')->where(['event' => 'ROLE_PERMISSIONS_UPDATED', 'entity_id' => $role])->first();
        $this->assertSame(['permissions' => []], json_decode($row->old_values, true));
        $this->assertSame(['permissions' => ['audit_log.view.all']], json_decode($row->new_values, true));
    }

    public function test_cannot_remove_the_last_role_manager(): void
    {
        $dev = $this->signIn('developer');
        $devRole = Role::where('slug', 'developer')->value('id');
        $keep = Permission::where('resource', '!=', 'role')->pluck('id')->all();
        $this->patchJson("/api/v1/roles/$devRole/permissions", ['permission_ids' => $keep])->assertStatus(422);
        $this->patchJson("/api/v1/users/{$dev->id}/deactivate")->assertStatus(422);
        $this->patchJson("/api/v1/users/{$dev->id}", ['role_id' => Role::where('slug', 'hr')->value('id')])->assertStatus(422);
        $this->makeUser('developer');                                   // a second holder makes it allowed
        $this->patchJson("/api/v1/users/{$dev->id}/deactivate")->assertOk();
    }

    public function test_create_user_command(): void
    {
        $this->artisan('identity:create-user', ['email' => 'Dev@Smart.Test', '--name' => 'Yousef'])
            ->expectsQuestion('Password', 'devpass123')->assertSuccessful();
        $this->postJson('/api/v1/auth/login', ['email' => 'dev@smart.test', 'password' => 'devpass123'])
            ->assertCreated()->assertJsonPath('data.user.role.slug', 'developer');
    }
}
