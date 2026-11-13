<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ViewAsTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])->json('data.token');
    }

    private function start(string $token, string $userId, ?string $reason = null): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/auth/view-as', ['user_id' => $userId, 'reason' => $reason]);
    }

    public function test_view_as_blocks_all_signature_routes(): void
    {
        $ceo = $this->makeUser('ceo');
        $view = $this->start($this->token($this->makeUser('developer')), $ceo->id)->assertCreated()->json('data.token');

        $this->withToken($view)->getJson('/api/v1/me/hr-signature')->assertForbidden();
        $this->withToken($view)->getJson('/api/v1/me/hr-signature/file')->assertForbidden();
        $this->withToken($view)->postJson('/api/v1/me/hr-signature', [])->assertForbidden();
    }

    private function me(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/auth/me');
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: string|null} old, new, user_id of the one row with this event */
    private function auditRow(string $event): array
    {
        $rows = DB::table('audit_log')->where('event', $event)->get();
        $this->assertCount(1, $rows, $event);

        return [json_decode($rows[0]->old_values ?? 'null', true), json_decode($rows[0]->new_values ?? 'null', true), $rows[0]->user_id];
    }

    private function grant(string $roleSlug, string ...$triples): void
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        foreach ($triples as $t) {
            [$resource, $action, $scope] = explode('.', $t);
            $role->permissions()->syncWithoutDetaching([Permission::where(compact('resource', 'action', 'scope'))->value('id')]);
        }
    }

    public function test_only_a_holder_of_user_impersonate_may_start(): void
    {
        $hr = $this->makeUser('hr');
        $this->start($this->token($hr), $this->makeUser('manager')->id)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
    }

    public function test_the_developer_views_as_the_site_manager_with_a_new_token(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $dev = $this->makeUser('developer', ['name' => 'يوسف']);
        $m = $this->makeUser('manager', ['name' => 'كريم']);
        $devToken = $this->token($dev);

        $res = $this->start($devToken, $m->id, 'checking the site-day screen')->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.idle_timeout_seconds', 28800)
            ->assertJsonPath('data.user.id', $m->id)
            ->assertJsonPath('data.user.view_as.by', ['id' => $dev->id, 'name' => 'يوسف'])
            ->assertJsonPath('data.user.view_as.expires_at', '2026-11-05T10:30:00.000000Z');
        $this->assertStringNotContainsString('password', $res->getContent());
        $this->assertStringNotContainsString($m->password, $res->getContent());
        $viewToken = $res->json('data.token');

        $me = $this->me($viewToken)->assertOk()
            ->assertJsonPath('data.id', $m->id)
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.view_as.by.id', $dev->id);
        $this->assertStringNotContainsString('password', $me->getContent());
        $this->me($devToken)->assertStatus(401);
        $this->assertSame(1, DB::table('user_sessions')->whereNull('deleted_at')->count());

        [$old, $new, $actor] = $this->auditRow('VIEW_AS_STARTED');
        $this->assertSame($dev->id, $actor);
        $this->assertSame(DB::table('audit_log')->where('event', 'VIEW_AS_STARTED')->value('entity_id'), $m->id);
        $this->assertSame(DB::table('user_sessions')->where('user_id', $dev->id)->value('id'), $old['session_id']);
        $this->assertEquals(['session_id' => DB::table('user_sessions')->where('user_id', $m->id)->value('id'), 'expires_at' => '2026-11-05T10:30:00.000000Z', 'reason' => 'checking the site-day screen'], $new);
    }

    public function test_blocked_abilities_are_left_out_of_me(): void
    {
        $this->grant('hr', 'user.manage.all', 'role.manage.all');
        $view = $this->start($this->token($this->makeUser('developer')), $this->makeUser('hr')->id)->json('data.token');
        $perms = $this->me($view)->json('data.permissions');
        $this->assertContains('employee.create.all', $perms);
        $this->assertContains('user.view.all', $perms);
        $this->assertNotContains('user.manage.all', $perms);
        $this->assertNotContains('role.manage.all', $perms);
    }

    public function test_refusals_are_422_on_user_id(): void
    {
        $dev = $this->makeUser('developer');
        $token = $this->token($dev);
        $refusals = [
            'identity.view_as.self' => $dev->id,
            'identity.view_as.not_allowed' => $this->makeUser('developer')->id,
            'identity.view_as.inactive' => $this->makeUser('hr', ['is_active' => false])->id,
        ];
        foreach ($refusals as $key => $target) {
            $this->assertNotSame($key, trans($key, [], 'ar'), "$key has an Arabic line");
            $this->assertNotSame($key, trans($key, [], 'en'), "$key has an English line");
            $this->withHeader('Accept-Language', 'ar')->start($token, $target)->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'user_id')
                ->assertJsonPath('error.details.0.message', trans($key, [], 'ar'));
        }
        $this->start($token, $this->makeUser('hr')->id, str_repeat('x', 501))->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');
        $this->me($token)->assertOk();
        $this->assertSame(0, DB::table('audit_log')->where('event', 'VIEW_AS_STARTED')->count());
    }

    public function test_while_viewing_accounts_roles_and_view_as_are_refused(): void
    {
        $this->grant('hr', 'user.manage.all', 'role.manage.all');
        $hr = $this->makeUser('hr');
        $m = $this->makeUser('manager');
        $view = $this->start($this->token($this->makeUser('developer')), $hr->id)->json('data.token');
        $role = Role::where('slug', 'manager')->value('id');

        $this->withToken($view)->postJson('/api/v1/users', ['name' => 'x', 'email' => 'x@smart.test', 'password' => 'abcd1234', 'role_id' => $role])->assertStatus(403);
        $this->withToken($view)->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertStatus(403);
        $this->withToken($view)->patchJson("/api/v1/roles/$role/permissions", ['permission_ids' => []])->assertStatus(403);
        $this->start($view, $m->id)->assertStatus(403);
        $this->withToken($view)->getJson('/api/v1/users')->assertOk();
    }

    public function test_an_action_while_viewing_is_audited_as_the_developer_on_behalf_of_the_target(): void
    {
        $dev = $this->makeUser('developer', ['name' => 'يوسف']);
        $hr = $this->makeUser('hr', ['name' => 'منى']);
        $view = $this->start($this->token($dev), $hr->id)->json('data.token');

        $site = $this->withToken($view)->postJson('/api/v1/sites', ['name_ar' => 'سموحة', 'kind' => 'work'])->assertCreated()->json('data.id');
        $row = DB::table('audit_log')->where(['entity_type' => 'sites', 'entity_id' => $site, 'event' => 'CREATED'])->first();
        $this->assertSame($dev->id, $row->user_id);
        $this->assertSame($hr->id, $row->on_behalf_of);
        $this->assertSame($hr->id, DB::table('sites')->where('id', $site)->value('created_by'));

        $back = $this->withToken($view)->postJson('/api/v1/auth/view-as/end')->assertCreated()->json('data.token');
        $list = $this->withToken($back)->getJson("/api/v1/audit-log?entity_type=sites&entity_id=$site")->assertOk();
        $list->assertJsonPath('data.0.user', ['id' => $dev->id, 'name' => 'يوسف'])
            ->assertJsonPath('data.0.on_behalf_of', ['id' => $hr->id, 'name' => 'منى']);
        $started = collect($this->withToken($back)->getJson('/api/v1/audit-log?entity_type=users')->json('data'))->firstWhere('event', 'VIEW_AS_STARTED');
        $this->assertNull($started['on_behalf_of']);
    }

    public function test_return_swaps_back_to_the_developer(): void
    {
        $dev = $this->makeUser('developer', ['name' => 'يوسف']);
        $m = $this->makeUser('manager');
        $devToken = $this->token($dev);
        $this->withToken($devToken)->postJson('/api/v1/auth/view-as/end')->assertStatus(409)->assertJsonPath('error.code', 'not_viewing_as');

        $view = $this->start($devToken, $m->id, 'why')->json('data.token');
        $viewSession = DB::table('user_sessions')->where('user_id', $m->id)->value('id');
        $back = $this->withToken($view)->postJson('/api/v1/auth/view-as/end')->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $dev->id)
            ->assertJsonPath('data.user.view_as', null)
            ->json('data.token');

        $this->me($back)->assertOk()->assertJsonPath('data.id', $dev->id)->assertJsonPath('data.view_as', null);
        $this->assertContains('user.manage.all', $this->me($back)->json('data.permissions'));
        $this->me($view)->assertStatus(401);
        [$old, $new, $actor] = $this->auditRow('VIEW_AS_ENDED');
        $this->assertSame($dev->id, $actor);
        $this->assertSame(['session_id' => $viewSession], $old);
        $this->assertEquals(['ended_by' => 'returned', 'session_id' => DB::table('user_sessions')->where('user_id', $dev->id)->whereNull('deleted_at')->value('id')], $new);
        $this->assertSame('why', $this->auditRow('VIEW_AS_STARTED')[1]['reason']);
    }

    public function test_logout_while_viewing_ends_it(): void
    {
        $dev = $this->makeUser('developer');
        $m = $this->makeUser('manager');
        $view = $this->start($this->token($dev), $m->id)->json('data.token');
        $this->withToken($view)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->me($view)->assertStatus(401);
        [, $new, $actor] = $this->auditRow('VIEW_AS_ENDED');
        $this->assertSame(['ended_by' => 'logout'], $new);
        $this->assertSame($dev->id, $actor);
        $this->assertSame(0, DB::table('audit_log')->where('event', 'LOGOUT')->count());
        $this->assertSame(0, DB::table('user_sessions')->whereNull('deleted_at')->count());
    }

    public function test_a_view_as_expires_after_30_minutes(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $dev = $this->makeUser('developer');
        $view = $this->start($this->token($dev), $this->makeUser('manager')->id)->json('data.token');
        $this->travelTo('2026-11-05 10:29:59');
        $this->me($view)->assertOk();
        $this->travelTo('2026-11-05 10:30:01');
        $this->me($view)->assertStatus(401);
        $this->me($view)->assertStatus(401);
        [, $new, $actor] = $this->auditRow('VIEW_AS_ENDED');
        $this->assertSame(['ended_by' => 'expired'], $new);
        $this->assertSame($dev->id, $actor);
    }

    public function test_deactivating_the_developer_revokes_the_view_as(): void
    {
        $dev = $this->makeUser('developer');
        $other = $this->makeUser('developer');
        $view = $this->start($this->token($dev), $this->makeUser('manager')->id)->json('data.token');
        $this->withToken($this->token($other))->patchJson("/api/v1/users/{$dev->id}/deactivate")->assertOk();
        $this->me($view)->assertStatus(401);
        $this->assertSame(['ended_by' => 'revoked'], $this->auditRow('VIEW_AS_ENDED')[1]);
    }

    public function test_removing_the_permission_revokes_the_view_as(): void
    {
        $dev = $this->makeUser('developer');
        $view = $this->start($this->token($dev), $this->makeUser('manager')->id)->json('data.token');
        $devRole = Role::where('slug', 'developer')->firstOrFail();
        $keep = $devRole->permissions->reject(fn (Permission $p) => $p->resource === 'user' && $p->action === 'impersonate')->pluck('id')->all();
        $this->withToken($this->token($this->makeUser('developer')))->patchJson("/api/v1/roles/{$devRole->id}/permissions", ['permission_ids' => $keep])->assertOk();
        $this->me($view)->assertStatus(401);
        $this->assertSame(['ended_by' => 'revoked'], $this->auditRow('VIEW_AS_ENDED')[1]);
    }

    /** @return array{0: User, 1: string} the target and the view-as token */
    private function viewingAsManager(): array
    {
        $m = $this->makeUser('manager');

        return [$m, $this->start($this->token($this->makeUser('developer')), $m->id)->json('data.token')];
    }

    private function assertRevoked(string $view): void
    {
        $this->me($view)->assertStatus(401);
        $this->me($view)->assertStatus(401);
        $this->assertSame(['ended_by' => 'revoked'], $this->auditRow('VIEW_AS_ENDED')[1]);
    }

    public function test_deactivating_the_target_revokes_the_view_as(): void
    {
        [$m, $view] = $this->viewingAsManager();
        $this->withToken($this->token($this->makeUser('developer')))->patchJson("/api/v1/users/{$m->id}/deactivate")->assertOk();
        $this->assertRevoked($view);
    }

    public function test_setting_the_target_password_revokes_the_view_as(): void
    {
        [$m, $view] = $this->viewingAsManager();
        $this->withToken($this->token($this->makeUser('developer')))->putJson("/api/v1/users/{$m->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->assertRevoked($view);
    }

    public function test_terminating_the_view_as_session_revokes_it(): void
    {
        [$m, $view] = $this->viewingAsManager();
        $session = DB::table('user_sessions')->where('user_id', $m->id)->value('id');
        $this->withToken($this->token($this->makeUser('developer')))->deleteJson("/api/v1/users/{$m->id}/sessions/$session")->assertNoContent();
        $this->assertRevoked($view);
        $this->assertSame(['session_id' => $session], $this->auditRow('ADMIN_SESSION_TERMINATED')[0]);
    }

    public function test_locking_the_target_revokes_the_view_as(): void
    {
        [$m, $view] = $this->viewingAsManager();
        User::whereKey($m->id)->update(['locked_until' => now()->addMinutes(15)]);
        $this->assertRevoked($view);
    }

    public function test_setting_the_developer_password_revokes_the_view_as(): void
    {
        $dev = $this->makeUser('developer');
        $view = $this->start($this->token($dev), $this->makeUser('manager')->id)->json('data.token');
        $this->withToken($this->token($this->makeUser('developer')))->putJson("/api/v1/users/{$dev->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->withToken($view)->postJson('/api/v1/auth/view-as/end')->assertStatus(401);
        $this->assertRevoked($view);
    }
}
