<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Presentation\RequestScope;
use ArgumentCountError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        $this->assertSame(48, Permission::count());
        $this->assertSame(['attendance.record.own', 'employee.crew.own', 'employee.propose_laborer.own', 'employee.view.own', 'leave.request.own', 'leave.view.own', 'site.view.own'], $grants('manager'));
        $this->assertSame(['attendance.view.all', 'audit_log.view.all', 'employee.view.all', 'employee_document.view.all', 'hr_forms.approve.all', 'hr_forms.approve_leave_request.all', 'hr_forms.view.all', 'hr_signatures.manage.own', 'leave.decide_escalated.all', 'leave.view.all', 'payroll.view.all', 'setting.view.all', 'site.view.all'], $grants('ceo'));
        $this->assertCount(33, $grants('hr'));
        foreach (['attendance.view.all', 'attendance.edit.all', 'attendance.lock.all', 'attendance_request.decide.all', 'holiday.manage.all', 'employee.assign_site.all', 'employee.approve_wage.all', 'employee.edit_code.all', 'employee_document.view.all', 'employee_document.edit.all', 'fare.manage.all', 'leave.view.all', 'leave.request.all', 'leave.decide.all', 'leave.adjust.all', 'payroll.view.all', 'payroll.manage.all'] as $name) {
            $this->assertContains($name, $grants('hr'));
        }
        $this->assertContains('user.manage.all', $grants('hr'));        // R22: within reach, see HrAccountsTest
        $this->assertNotContains('role.manage.all', $grants('hr'));
        $this->assertNotContains('leave.decide_escalated.all', $grants('hr'));
        $this->assertCount(37, $grants('developer'));
        $this->assertContains('payroll.manage.all', $grants('developer'));
        $this->assertContains('hr_forms.generate.all', $grants('developer'));
        $this->assertContains('hr_forms.view.all', $grants('developer'));
        $this->assertNotContains('hr_forms.approve.all', $grants('developer'));
        $this->assertNotContains('hr_forms.approve_leave_request.all', $grants('developer'));
        $this->assertContains('hr_forms.approve_leave_request.all', $grants('hr'));
        $this->assertNotContains('hr_signatures.manage.own', $grants('developer'));
        $this->assertContains('hr_forms.generate.all', $grants('hr'));
        $this->assertContains('hr_forms.view.all', $grants('hr'));
        $this->assertContains('hr_signatures.manage.own', $grants('hr'));
        $this->assertNotContains('hr_forms.approve.all', $grants('hr'));
        $this->assertNotContains('leave.view.own', $grants('developer'));
        $this->assertContains('system_log.view.all', $grants('developer'));
        $this->assertContains('user.impersonate.all', $grants('developer'));
        foreach (['ceo', 'hr', 'manager'] as $slug) {
            $this->assertNotContains('system_log.view.all', $grants($slug), $slug);
            $this->assertEmpty(array_filter($grants($slug), fn ($t) => str_starts_with($t, 'user.impersonate.')), $slug);
        }
    }

    public function test_every_api_route_requires_auth_and_a_permission(): void
    {
        $open = ['api/v1/health', 'api/v1/auth/login'];
        $authOnly = ['api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/auth/view-as/end', 'api/v1/notifications', 'api/v1/notifications/{notification}/read'];
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

    public function test_the_narrowest_scope_needs_at_least_one_permission(): void
    {
        $this->expectException(ArgumentCountError::class);   // zero abilities would read as `all` (fails open)
        RequestScope::narrowestOwnerId(new Request);
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
        $this->assertNotContains('role.manage.all', $perms);
    }
}
