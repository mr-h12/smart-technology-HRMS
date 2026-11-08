<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** HR manages HR and team-lead accounts, never one whose role holds an `.all` grant HR lacks (owner, 2026-11-08, R22). */
class HrAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function roleId(string $slug): string
    {
        return (string) Role::where('slug', $slug)->value('id');
    }

    private function assertOutOfReach(TestResponse $res): void
    {
        $res->assertStatus(403)->assertJsonPath('error.code', 'account_out_of_reach')
            ->assertJsonPath('error.message', __('identity.refusal.account_out_of_reach'));
    }

    public function test_hr_creates_a_team_lead_account(): void
    {
        $this->signIn('hr');
        $id = $this->postJson('/api/v1/users', ['name' => 'منى', 'email' => 'mona@smart.test', 'password' => 'abcd1234', 'role_id' => $this->roleId('manager')])
            ->assertCreated()->assertJsonPath('data.role.slug', 'manager')->json('data.id');
        $this->assertDatabaseHas('audit_log', ['event' => 'USER_CREATED', 'entity_id' => $id]);
    }

    public function test_hr_sets_an_hr_accounts_password(): void
    {
        $other = $this->makeUser('hr');
        $this->signIn('hr');
        $this->putJson("/api/v1/users/{$other->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->assertDatabaseHas('audit_log', ['event' => 'PASSWORD_SET', 'entity_id' => $other->id]);
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'newpass99'])->assertCreated();
    }

    public function test_hr_is_refused_every_action_on_ceo_and_developer_accounts(): void
    {
        $hr = $this->signIn('hr');
        foreach (['ceo', 'developer'] as $slug) {
            $target = $this->makeUser($slug);
            $this->assertOutOfReach($this->patchJson("/api/v1/users/{$target->id}", ['name' => 'x']));
            $this->assertOutOfReach($this->putJson("/api/v1/users/{$target->id}/password", ['password' => 'newpass99']));
            $this->assertOutOfReach($this->patchJson("/api/v1/users/{$target->id}/deactivate"));
            $this->assertOutOfReach($this->patchJson("/api/v1/users/{$target->id}/reactivate"));
            $this->assertOutOfReach($this->getJson("/api/v1/users/{$target->id}/sessions"));
            $this->assertOutOfReach($this->deleteJson("/api/v1/users/{$target->id}/sessions/".$target->id));
            $fresh = $target->fresh();
            $this->assertSame($slug, $fresh->name);
            $this->assertTrue($fresh->is_active);
            $this->postJson('/api/v1/auth/login', ['email' => $target->email, 'password' => 'secret123'])->assertCreated();
            $this->actingAs($hr, 'api');
        }
        $this->assertSame(0, DB::table('audit_log')->whereIn('event', ['USER_UPDATED', 'PASSWORD_SET', 'USER_DEACTIVATED', 'ADMIN_SESSION_TERMINATED'])->count());
    }

    public function test_hr_is_refused_assigning_the_ceo_or_developer_role(): void
    {
        $this->makeUser('developer'); // a role change needs a role manager to remain
        $lead = $this->makeUser('manager');
        $this->signIn('hr');
        foreach (['ceo', 'developer'] as $slug) {
            $this->assertOutOfReach($this->postJson('/api/v1/users', ['name' => 'x', 'email' => "$slug@smart.test", 'password' => 'abcd1234', 'role_id' => $this->roleId($slug)]));
            $this->assertOutOfReach($this->patchJson("/api/v1/users/{$lead->id}", ['role_id' => $this->roleId($slug)]));
        }
        $this->assertDatabaseMissing('users', ['email' => 'ceo@smart.test']);
        $this->assertSame($this->roleId('manager'), (string) $lead->fresh()->role_id);
        $this->patchJson("/api/v1/users/{$lead->id}", ['role_id' => $this->roleId('hr')])->assertOk()->assertJsonPath('data.role.slug', 'hr');
    }

    public function test_the_developer_still_manages_every_account(): void
    {
        $ceo = $this->makeUser('ceo');
        $dev2 = $this->makeUser('developer');
        $this->signIn('developer');
        $this->postJson('/api/v1/users', ['name' => 'x', 'email' => 'boss@smart.test', 'password' => 'abcd1234', 'role_id' => $this->roleId('ceo')])->assertCreated();
        $this->patchJson("/api/v1/users/{$ceo->id}", ['name' => 'المدير'])->assertOk();
        $this->putJson("/api/v1/users/{$dev2->id}/password", ['password' => 'newpass99'])->assertNoContent();
        $this->patchJson("/api/v1/users/{$dev2->id}/deactivate")->assertOk();
        $this->getJson("/api/v1/users/{$ceo->id}/sessions")->assertOk();
        $this->assertSame(['ceo', 'developer', 'hr', 'manager'], collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all());
        $this->assertSame([true], collect($this->getJson('/api/v1/users')->json('data'))->pluck('manageable')->unique()->values()->all());
    }

    public function test_a_team_lead_without_user_manage_is_refused(): void
    {
        $this->signIn('manager');
        $this->postJson('/api/v1/users', ['name' => 'x', 'email' => 'x@smart.test', 'password' => 'abcd1234', 'role_id' => $this->roleId('manager')])
            ->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->getJson('/api/v1/users/assignable-roles')->assertStatus(403);
    }

    public function test_hr_sees_which_accounts_and_roles_are_in_reach(): void
    {
        foreach (['ceo', 'developer', 'manager'] as $slug) {
            $this->makeUser($slug);
        }
        $this->signIn('hr');
        $this->assertSame(['hr', 'manager'], collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all());
        $reach = collect($this->getJson('/api/v1/users')->json('data'))->mapWithKeys(fn (array $u) => [$u['role']['slug'] => $u['manageable']])->all();
        $this->assertEquals(['ceo' => false, 'developer' => false, 'hr' => true, 'manager' => true], $reach);
    }

    public function test_the_refusal_has_an_arabic_and_an_english_line(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $this->assertNotSame('identity.refusal.account_out_of_reach', trans('identity.refusal.account_out_of_reach', [], $locale), $locale);
        }
    }
}
