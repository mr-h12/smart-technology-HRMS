<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Administration\RoleAdministration;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\RolePermissions;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** What one authenticated request costs once the caches are warm. */
class HotPathTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the SQL the request ran */
    private function queriesOf(string $token, string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson($uri)->assertOk();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    private function token(string $roleSlug): string
    {
        $user = $this->makeUser($roleSlug);

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret123'])->json('data.token');
    }

    public function test_me_on_a_warm_cache_reads_session_user_and_role_and_writes_nothing(): void
    {
        $token = $this->token('hr');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $sql = $this->queriesOf($token, '/api/v1/auth/me');

        $this->assertCount(3, $sql, implode("\n", $sql));
        $this->assertSame([], preg_grep('/^\s*(update|insert|delete)/i', $sql));
    }

    public function test_employee_list_on_a_warm_cache_adds_only_its_own_two_queries(): void
    {
        $token = $this->token('hr');
        $this->withToken($token)->postJson('/api/v1/employees', ['name_ar' => 'أحمد', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000'])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/employees')->assertOk();

        $sql = $this->queriesOf($token, '/api/v1/employees');

        // session + user before the controller; count + page inside it.
        $this->assertCount(4, $sql, implode("\n", $sql));
        $this->assertSame([], preg_grep('/^\s*(update|insert|delete)/i', $sql));
    }

    public function test_session_touch_is_skipped_within_a_minute(): void
    {
        $this->travelTo('2026-10-17 10:00:00');
        $token = $this->token('hr');
        $touched = fn () => DB::table('user_sessions')->where('session_id', hash('sha256', $token))->value('last_activity_at');

        $this->travelTo('2026-10-17 10:01:00');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertStringStartsWith('2026-10-17 10:00:00', $touched());

        $this->travelTo('2026-10-17 10:01:01');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertStringStartsWith('2026-10-17 10:01:01', $touched());
    }

    public function test_an_untouched_session_still_expires_8_hours_after_its_last_write(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $token = $this->token('hr');
        $this->travelTo('2026-10-17 08:00:59');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();   // inside the minute: not written
        $this->travelTo('2026-10-17 16:00:01');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    private function auditor(): Role
    {
        $role = Role::create(['slug' => 'auditor', 'name' => 'Auditor', 'name_ar' => 'مراجع']);
        $role->permissions()->attach(Permission::where(['resource' => 'employee', 'action' => 'view', 'scope' => 'all'])->value('id'));

        return $role;
    }

    public function test_revoking_a_permission_denies_the_very_next_request(): void
    {
        $role = $this->auditor();
        $auditor = $this->signIn('auditor');
        $this->getJson('/api/v1/employees')->assertOk();

        $this->signIn('developer');
        $this->patchJson("/api/v1/roles/{$role->id}/permissions", ['permission_ids' => []])->assertOk();

        $this->actingAs($auditor, 'api');
        $this->getJson('/api/v1/employees')->assertStatus(403);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_a_cold_read_that_loaded_grants_before_a_revoke_commits_cannot_cache_them(): void
    {
        $this->makeUser('developer');   // keeps a role manager, which syncPermissions requires
        $role = $this->auditor();
        $auditor = $this->makeUser('auditor');
        // The interleaving: the reader's DB read returns the old grants, then the revoke commits, then the reader writes the cache.
        $revoked = false;
        DB::listen(function (QueryExecuted $q) use (&$revoked, $role): void {
            if (! $revoked && str_contains($q->sql, 'inner join "role_permissions"')) {
                $revoked = true;
                app(RoleAdministration::class)->syncPermissions((string) $role->id, []);
            }
        });

        $this->assertSame(['employee.view.all'], RolePermissions::of((string) $role->id));   // the late, stale write

        $this->actingAs($auditor, 'api');
        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_deleting_a_role_denies_its_users_on_the_next_request(): void
    {
        $role = $this->auditor();
        $this->signIn('auditor');
        $this->getJson('/api/v1/employees')->assertOk();

        $role->delete();

        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_moving_a_user_to_another_role_applies_on_the_next_request(): void
    {
        $this->auditor();
        $empty = Role::create(['slug' => 'empty', 'name' => 'Empty', 'name_ar' => 'فارغ']);
        $auditor = $this->signIn('auditor');
        $this->getJson('/api/v1/employees')->assertOk();

        $this->signIn('developer');
        $this->patchJson("/api/v1/users/{$auditor->id}", ['role_id' => $empty->id])->assertOk();

        $this->actingAs($auditor->fresh(), 'api');
        $this->getJson('/api/v1/employees')->assertStatus(403);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', []);
    }

    public function test_a_migration_that_grants_a_permission_applies_on_the_next_request(): void
    {
        $role = $this->auditor();
        $this->signIn('auditor');
        $this->getJson('/api/v1/sites')->assertStatus(403);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => Permission::where(['resource' => 'site', 'action' => 'view', 'scope' => 'all'])->value('id'),
        ]);
        Event::dispatch(new MigrationsEnded('up'));

        $this->getJson('/api/v1/sites')->assertOk();
    }

    public function test_foreign_keys_and_search_columns_are_indexed(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexdef', 'indexname');
        foreach (['user_sessions.user_id', 'users.role_id', 'fares.site_id', 'manager_sites.site_id', 'employee_manager_assignments.manager_id', 'role_permissions.permission_id', 'employees.residence_id', 'employee_fare_overrides.site_id'] as $column) {
            [$table, $col] = explode('.', $column);
            $this->assertArrayHasKey("{$table}_{$col}_index", $indexes, $column);
        }
        foreach (['name_ar', 'name_en', 'national_id'] as $col) {
            $this->assertStringContainsString("gin ({$col} gin_trgm_ops)", $indexes["employees_{$col}_trgm_index"] ?? '', $col);
        }
    }
}
