<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_matrix(): void
    {
        $grants = fn (string $slug) => Role::where('slug', $slug)->firstOrFail()->permissions
            ->map(fn ($p) => "{$p->resource}.{$p->action}.{$p->scope}")->sort()->values()->all();
        $this->assertSame(15, Permission::count());
        $this->assertSame(['employee.view.own', 'site.view.own'], $grants('manager'));
        $this->assertSame(['audit_log.view.all', 'employee.view.all', 'site.view.all'], $grants('ceo'));
        $this->assertCount(9, $grants('hr'));
        $this->assertNotContains('user.manage.all', $grants('hr'));
        $this->assertCount(13, $grants('developer'));
    }

    public function test_every_api_route_requires_auth_and_a_permission(): void
    {
        $open = ['api/v1/health', 'api/v1/auth/login'];
        $authOnly = ['api/v1/auth/me', 'api/v1/auth/logout'];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1') || in_array($route->uri(), $open, true)) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $this->assertContains('auth', $mw, $route->uri());
            if (! in_array($route->uri(), $authOnly, true)) {
                $this->assertNotEmpty(array_filter($mw, fn ($m) => str_starts_with($m, 'permission:')), $route->uri());
            }
        }
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/employees')->assertStatus(401);
    }

    public function test_role_matrix_on_real_routes(): void
    {
        $this->signIn('manager');
        $this->postJson('/api/v1/employees', [])->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->signIn('ceo');
        $this->getJson('/api/v1/sites')->assertOk();
        $this->postJson('/api/v1/sites', ['name_ar' => 'موقع', 'kind' => 'work'])->assertStatus(403);
        $this->signIn('hr');
        $this->postJson('/api/v1/sites', ['name_ar' => 'موقع', 'kind' => 'work'])->assertCreated();
    }

    public function test_a_refusal_is_not_reported_to_the_log(): void
    {
        Exceptions::fake();
        $this->signIn('manager');
        $this->postJson('/api/v1/employees', [])->assertStatus(403);
        Exceptions::assertNotReported(AuthorizationRefused::class);
    }

    public function test_permission_is_checked_by_name_not_role(): void
    {
        $role = Role::create(['slug' => 'auditor', 'name' => 'Auditor', 'name_ar' => 'مراجع']);
        $role->permissions()->attach(Permission::where(['resource' => 'site', 'action' => 'view', 'scope' => 'all'])->value('id'));
        $this->signIn('auditor');
        $this->getJson('/api/v1/sites')->assertOk();
        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_me_lists_permissions(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->makeUser('hr')->email, 'password' => 'secret123'])->json('data.token');
        $perms = $this->withToken($token)->getJson('/api/v1/auth/me')->json('data.permissions');
        $this->assertContains('employee.create.all', $perms);
        $this->assertNotContains('user.manage.all', $perms);
    }
}
