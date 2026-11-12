<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Application\Administration\RoleAdministration;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\Notifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** HR or the Developer makes an employee a site manager in one step: account, link, sites and engineers (owner, 2026-11-12). */
class SiteManagerAccountTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const EMAIL = 'site.manager@smart.test';

    private const PASSWORD = 'abcd1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-08-10 10:00');
        $this->makeUser('developer'); // a deactivation or grant change needs a role manager to remain, as in every deployment
        $this->signIn('hr');
    }

    /** @param  array<string, mixed>  $body */
    private function make(string $employeeId, array $body): TestResponse
    {
        return $this->postJson("/api/v1/employees/{$employeeId}/site-manager", $body);
    }

    /** @param  array<string, mixed>  $body */
    private function edit(string $employeeId, array $body): TestResponse
    {
        return $this->putJson("/api/v1/employees/{$employeeId}/site-manager", $body);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return ['email' => self::EMAIL, 'password' => self::PASSWORD, ...$extra];
    }

    /** @param  array<string, string>  $params */
    private function assertRefused(TestResponse $r, string $field, string $key, array $params = []): void
    {
        $r->assertStatus(422)->assertJsonPath('error.details.0.field', $field)->assertJsonPath('error.details.0.message', __("errors.{$key}", $params));
    }

    private function assertField(TestResponse $r, string $field): void
    {
        $r->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
    }

    private function linkOf(string $employeeId): ?string
    {
        $id = DB::table('employees')->where('id', $employeeId)->value('user_id');

        return $id === null ? null : (string) $id;
    }

    public function test_hr_makes_an_employee_a_site_manager_in_one_step(): void
    {
        [$s1, $s2, $s3] = [$this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];
        $self = $this->employee('permanent_worker');       // Worker-1 becomes the site manager, homed at one of their own sites
        $this->homeSite($self, $s1, '2026-01-01');
        $worker = $this->employee('permanent_worker');     // Worker-2
        $this->homeSite($worker, $s1, '2026-01-01');
        $this->homeSite($this->employee('permanent_worker'), $s3, '2026-01-01');
        $engineer = $this->employee('engineer');           // Engineer-1, ticked
        $this->employee('engineer');                       // Engineer-2, not ticked

        $res = $this->make($self, $this->body(['site_ids' => [$s1, $s2], 'engineer_ids' => [$engineer], 'effective_from' => '2026-08-01']))
            ->assertCreated()
            ->assertJsonPath('data.id', $self)
            ->assertJsonPath('data.site_manager.email', self::EMAIL)
            ->assertJsonPath('data.site_manager.is_active', true)
            ->assertJsonPath('data.site_manager.role.slug', 'manager')
            ->assertJsonPath('data.site_manager.engineer_ids', [$engineer])
            ->assertJsonMissingPath('data.user_id');
        $this->assertEqualsCanonicalizing([$s1, $s2], $res->json('data.site_manager.site_ids'));
        $userId = (string) $res->json('data.site_manager.id');
        $this->assertSame($userId, $this->linkOf($self));
        $this->assertContains($userId, array_column($this->getJson('/api/v1/sites/manager-candidates')->assertOk()->json('data'), 'id'));

        $this->assertDatabaseHas('audit_log', ['event' => 'USER_CREATED', 'entity_type' => 'users', 'entity_id' => $userId]);
        $link = DB::table('audit_log')->where(['event' => 'UPDATED', 'entity_type' => 'employees', 'entity_id' => $self])->sole();
        $this->assertSame(['user_id' => null], json_decode((string) $link->old_values, true));
        $this->assertSame(['user_id' => $userId], json_decode((string) $link->new_values, true));
        $this->assertEqualsCanonicalizing([$s1, $s2], DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->pluck('entity_id')->all());
        $this->assertSame(1, DB::table('audit_log')->where(['event' => 'CREATED', 'entity_type' => 'employee_manager_assignments'])->count());
        foreach (['%password%', '%'.self::PASSWORD.'%'] as $like) {
            $this->assertSame(0, DB::table('audit_log')->whereRaw("coalesce(old_values::text, '') || coalesce(new_values::text, '') like ?", [$like])->count());
        }

        $token = $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $userId);
        $this->assertSame(['Worker-2', 'Engineer-1'], array_column($this->withToken($token)->getJson('/api/v1/employees')->assertOk()->json('data'), 'name_ar'));
        $this->withToken($token)->getJson("/api/v1/employees/{$self}")->assertNotFound();
    }

    public function test_the_developer_can_do_it_too(): void
    {
        $this->signIn('developer');
        $site = $this->workSite();
        $this->make($this->employee('engineer'), $this->body(['site_ids' => [$site]]))
            ->assertCreated()->assertJsonPath('data.site_manager.role.slug', 'manager')->assertJsonPath('data.site_manager.site_ids', [$site]);
    }

    public function test_a_site_manager_and_the_ceo_are_refused(): void
    {
        $site = $this->workSite();
        $linked = $this->employee('engineer');
        $this->make($linked, $this->body(['site_ids' => [$site]]))->assertCreated();
        $plain = $this->employee('engineer');
        $callers = [$this->makeUser('manager'), $this->makeUser('ceo')];
        $before = [DB::table('users')->count(), DB::table('audit_log')->count(), DB::table('manager_sites')->count()];

        foreach ($callers as $caller) {
            $this->actingAs($caller, 'api');
            $this->make($plain, $this->body(['email' => 'refused@smart.test', 'site_ids' => [$site]]))->assertForbidden();
            $this->edit($linked, ['site_ids' => []])->assertForbidden();
        }
        $this->assertSame($before, [DB::table('users')->count(), DB::table('audit_log')->count(), DB::table('manager_sites')->count()]);
        $this->assertNull($this->linkOf($plain));
    }

    public function test_the_account_always_gets_the_one_site_manager_role(): void
    {
        $this->make($this->employee('engineer'), $this->body(['role_id' => Role::where('slug', 'hr')->value('id')]))
            ->assertCreated()->assertJsonPath('data.site_manager.role.slug', 'manager');

        $second = Role::create(['slug' => 'site-lead', 'name' => 'site-lead', 'name_ar' => 'site-lead']);
        $second->permissions()->attach(Permission::where(['resource' => 'site', 'action' => 'view', 'scope' => 'own'])->value('id'));
        $other = $this->employee('engineer');
        $this->assertRefused($this->make($other, $this->body(['email' => 'second@smart.test'])), 'role', 'site_manager_role');
        $this->assertDatabaseMissing('users', ['email' => 'second@smart.test']);
        $this->assertNull($this->linkOf($other));
    }

    public function test_invalid_input_is_refused_and_leaves_nothing(): void
    {
        $site = $this->workSite();
        $leave = $this->leaveTypes()['sick'];
        $self = $this->employee('engineer');                                                      // Engineer-1
        $worker = $this->employee('permanent_worker');                                            // Worker-1
        $left = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-07-31']);  // Engineer-2, archived
        $this->makeUser('hr', ['email' => 'taken@smart.test']);
        $users = DB::table('users')->count();

        $this->assertField($this->make($self, $this->body(['email' => 'taken@smart.test', 'site_ids' => [$site]])), 'email');
        $this->assertField($this->make($self, $this->body(['password' => 'short'])), 'password');
        $this->assertField($this->make($self, $this->body(['site_ids' => [$leave]])), 'site_ids.0');
        $this->assertField($this->make($self, $this->body(['site_ids' => ['not-a-uuid']])), 'site_ids.0');
        foreach ([$worker => 'Worker-1', $left => 'Engineer-2', $self => 'Engineer-1'] as $id => $name) {
            $this->assertRefused($this->make($self, $this->body(['site_ids' => [$site], 'engineer_ids' => [$id], 'effective_from' => '2026-08-01'])),
                'engineer_ids', 'not_an_engineer', ['name' => $name]);
        }
        $this->assertField($this->make($self, $this->body(['engineer_ids' => [$worker]])), 'effective_from');

        $this->assertSame($users, DB::table('users')->count());
        $this->assertNull($this->linkOf($self));
        $this->assertSame(0, DB::table('manager_sites')->count());
    }

    public function test_a_locked_period_refuses_the_whole_step(): void
    {
        $this->leaveTypes();
        $site = $this->workSite();
        $self = $this->employee('office');
        $engineer = $this->employee('engineer');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-06-26', 'confirm_missing' => true])->assertCreated();
        $audit = DB::table('audit_log')->count();

        $this->assertRefused($this->make($self, $this->body(['site_ids' => [$site], 'engineer_ids' => [$engineer], 'effective_from' => '2026-07-10'])),
            'engineer_ids', 'engineer_refused', ['name' => 'Engineer-1', 'reason' => __('errors.dated_change_period_locked')]);
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        $this->assertNull($this->linkOf($self));
        $this->assertSame(0, DB::table('manager_sites')->count());
        $this->assertSame(0, DB::table('employee_manager_assignments')->count());
        $this->assertSame($audit, DB::table('audit_log')->count());
    }

    public function test_a_duplicate_date_names_the_engineer(): void
    {
        $self = $this->employee('office');
        $engineer = $this->employee('engineer');
        $this->postJson("/api/v1/employees/{$engineer}/manager-assignments", ['manager_id' => $this->makeUser('manager')->id, 'effective_from' => '2026-08-01'])->assertCreated();

        $this->assertRefused($this->make($self, $this->body(['engineer_ids' => [$engineer], 'effective_from' => '2026-08-01'])),
            'engineer_ids', 'engineer_refused', ['name' => 'Engineer-1', 'reason' => __('errors.duplicate_date')]);
        $this->assertNull($this->linkOf($self));
    }

    public function test_an_employee_is_linked_once_and_never_once_archived(): void
    {
        $e = $this->employee('engineer');
        $this->make($e, $this->body())->assertCreated();
        $this->assertRefused($this->make($e, $this->body(['email' => 'again@smart.test'])), 'employee', 'already_site_manager');
        $this->assertDatabaseMissing('users', ['email' => 'again@smart.test']);

        $left = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-07-31']);
        $this->make($left, $this->body(['email' => 'left@smart.test']))->assertStatus(409)->assertJsonPath('error.code', 'employee_archived');
        $this->assertDatabaseMissing('users', ['email' => 'left@smart.test']);
    }

    public function test_an_existing_site_manager_account_is_linked_and_keeps_its_sites(): void
    {
        $site = $this->workSite();
        $account = $this->managerOf($site);
        $this->make($this->employee('engineer'), ['user_id' => $account->id])->assertCreated()
            ->assertJsonPath('data.site_manager.id', $account->id)
            ->assertJsonPath('data.site_manager.site_ids', [$site]);
        $this->assertSame(0, DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->count());
        $this->assertSame(0, DB::table('audit_log')->where('event', 'USER_CREATED')->count());

        $other = $this->employee('engineer');
        $this->assertRefused($this->make($other, ['user_id' => $account->id]), 'user_id', 'account_linked');
        $this->assertRefused($this->make($other, ['user_id' => $this->makeUser('hr')->id]), 'user_id', 'not_site_manager_account');
        $this->assertRefused($this->make($other, ['user_id' => $this->makeUser('manager', ['is_active' => false])->id]), 'user_id', 'not_site_manager_account');
        $this->assertNull($this->linkOf($other));
    }

    public function test_hr_changes_the_sites_and_adds_engineers_later(): void
    {
        [$s1, $s2, $s3] = [$this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];
        $other = $this->managerOf($s1);
        $self = $this->employee('office');
        $engineer = $this->employee('engineer');
        $userId = (string) $this->make($self, $this->body(['site_ids' => [$s1, $s2], 'engineer_ids' => [$engineer], 'effective_from' => '2026-08-01']))
            ->assertCreated()->json('data.site_manager.id');
        $audited = DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->pluck('id')->all();

        $res = $this->edit($self, ['site_ids' => [$s2, $s3], 'engineer_ids' => [$engineer], 'effective_from' => '2026-08-05'])
            ->assertOk()->assertJsonPath('data.site_manager.engineer_ids', [$engineer]);
        $this->assertEqualsCanonicalizing([$s2, $s3], $res->json('data.site_manager.site_ids'));
        $this->assertEqualsCanonicalizing([$s1, $s3], DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->whereNotIn('id', $audited)->pluck('entity_id')->all());
        $this->assertSame([$other->id], DB::table('manager_sites')->where('site_id', $s1)->pluck('manager_id')->all());
        $this->assertSame(1, DB::table('employee_manager_assignments')->where('employee_id', $engineer)->count());

        $this->edit($this->employee('engineer'), ['site_ids' => [$s1]])->assertNotFound();
        User::whereKey($userId)->update(['is_active' => false]);
        $this->assertRefused($this->edit($self, ['site_ids' => [$s1]]), 'account', 'not_site_manager_account');
    }

    public function test_only_hr_and_the_developer_see_the_account_and_never_the_raw_link(): void
    {
        [$s1, $s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        $first = $this->employee('permanent_worker');   // Worker-1, homed at Site-1, site manager of Site-1
        $second = $this->employee('permanent_worker');  // Worker-2, homed at Site-1, site manager of Site-2
        $this->homeSite($first, $s1, '2026-01-01');
        $this->homeSite($second, $s1, '2026-01-01');
        $firstId = (string) $this->make($first, $this->body(['site_ids' => [$s1]]))->assertCreated()->json('data.site_manager.id');
        $secondId = (string) $this->make($second, $this->body(['email' => 'second@smart.test', 'site_ids' => [$s2]]))->assertCreated()->json('data.site_manager.id');

        $this->getJson("/api/v1/employees/{$second}")->assertOk()->assertJsonPath('data.site_manager.site_ids', [$s2])->assertJsonMissingPath('data.user_id');
        $plain = $this->getJson('/api/v1/employees/'.$this->employee('office'))->assertOk()->json('data');
        $this->assertArrayHasKey('site_manager', $plain);
        $this->assertNull($plain['site_manager']);
        foreach ($this->getJson('/api/v1/employees')->assertOk()->json('data') as $row) {
            $this->assertArrayNotHasKey('user_id', $row);
        }
        // PATCH never sets the link.
        $this->patchJson("/api/v1/employees/{$second}", ['user_id' => $firstId])->assertOk();
        $this->assertSame($secondId, $this->linkOf($second));

        $this->actingAs($this->makeUser('developer'), 'api');
        $this->getJson("/api/v1/employees/{$second}")->assertOk()->assertJsonPath('data.site_manager.id', $secondId);

        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->getJson("/api/v1/employees/{$second}")->assertOk()->assertJsonMissingPath('data.site_manager')->assertJsonMissingPath('data.user_id');

        $this->actingAs(User::findOrFail($firstId), 'api');
        $this->getJson("/api/v1/employees/{$second}")->assertOk()->assertJsonMissingPath('data.site_manager')->assertJsonMissingPath('data.current_pay');
        foreach ($this->getJson('/api/v1/employees')->assertOk()->json('data') as $row) {
            $this->assertArrayNotHasKey('user_id', $row);
        }
        $this->getJson("/api/v1/employees/{$first}")->assertNotFound();
    }

    /** @return list<array<string, mixed>> the new_values of the account's USER_DEACTIVATED audit rows */
    private function deactivations(string $userId): array
    {
        return DB::table('audit_log')->where(['event' => 'USER_DEACTIVATED', 'entity_id' => $userId])->pluck('new_values')
            ->map(fn (mixed $v) => json_decode((string) $v, true))->all();
    }

    private function isActive(string $userId): bool
    {
        return (bool) DB::table('users')->where('id', $userId)->value('is_active');
    }

    public function test_a_past_termination_date_deactivates_the_linked_account_at_once(): void
    {
        $e = $this->employee('engineer');
        $userId = (string) $this->make($e, $this->body())->assertCreated()->json('data.site_manager.id');
        $token = $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();
        $hr = $this->signIn('hr');

        $this->patchJson("/api/v1/employees/{$e}", ['termination_date' => '2026-08-09'])->assertOk()->assertJsonPath('data.archived', true);
        $this->assertFalse($this->isActive($userId));
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $userId)->whereNull('deleted_at')->count());
        $this->assertEquals([['is_active' => false, 'employee_id' => $e, 'reason' => 'employee_archived']], $this->deactivations($userId));
        $this->assertSame($hr->id, DB::table('audit_log')->where(['event' => 'USER_DEACTIVATED', 'entity_id' => $userId])->value('user_id'));

        // Reactivating the employee does not reactivate the account (owner, 2026-11-12).
        $this->patchJson("/api/v1/employees/{$e}", ['termination_date' => null])->assertOk()->assertJsonPath('data.archived', false);
        $this->assertFalse($this->isActive($userId));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->flushHeaders();
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_a_termination_date_of_today_deactivates_the_account_the_next_night(): void
    {
        $e = $this->employee('engineer');
        $userId = (string) $this->make($e, $this->body())->assertCreated()->json('data.site_manager.id');
        $this->patchJson("/api/v1/employees/{$e}", ['termination_date' => '2026-08-10'])->assertOk();
        $this->assertTrue($this->isActive($userId));

        $this->cairo('2026-08-11 00:05');
        app(EmployeeService::class)->deactivateArchivedAccounts();
        $this->assertFalse($this->isActive($userId));
        app(EmployeeService::class)->deactivateArchivedAccounts();
        $this->assertEquals([['is_active' => false, 'employee_id' => $e, 'reason' => 'employee_archived']], $this->deactivations($userId));
    }

    public function test_hr_save_deactivates_an_account_outside_hr_reach(): void
    {
        $e = $this->employee('engineer');
        $userId = (string) $this->make($e, $this->body())->assertCreated()->json('data.site_manager.id');
        $role = Role::where('slug', 'manager')->firstOrFail();
        $grants = [...$role->permissions()->pluck('permissions.id')->all(), Permission::where(['resource' => 'system_log', 'action' => 'view', 'scope' => 'all'])->value('id')];
        app(RoleAdministration::class)->syncPermissions((string) $role->id, array_map('strval', $grants));
        $this->patchJson("/api/v1/users/{$userId}/deactivate")->assertForbidden()->assertJsonPath('error.code', 'account_out_of_reach');

        $this->patchJson("/api/v1/employees/{$e}", ['termination_date' => '2026-08-01'])->assertOk();
        $this->assertFalse($this->isActive($userId));
    }

    public function test_an_unlinked_archived_employee_and_a_linked_active_one_are_untouched(): void
    {
        $active = $this->employee('engineer');
        $userId = (string) $this->make($active, $this->body())->assertCreated()->json('data.site_manager.id');
        $other = $this->makeUser('manager');
        $left = $this->employee('engineer');
        $this->patchJson("/api/v1/employees/{$left}", ['termination_date' => '2026-08-01'])->assertOk();

        app(EmployeeService::class)->deactivateArchivedAccounts();
        $this->assertTrue($this->isActive($userId));
        $this->assertTrue($this->isActive((string) $other->id));
        $this->assertSame(0, DB::table('audit_log')->where('event', 'USER_DEACTIVATED')->count());
    }

    public function test_the_sweep_runs_daily_at_00_05_cairo(): void
    {
        $e = $this->employee('engineer');
        $userId = (string) $this->make($e, $this->body())->assertCreated()->json('data.site_manager.id');
        DB::table('employees')->where('id', $e)->update(['termination_date' => '2026-08-01']);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'employees:deactivate-archived-accounts');
        $this->assertSame(['5 0 * * *', 'Africa/Cairo'], [$event?->expression, $event?->timezone]);
        $event->run($this->app);
        $this->assertFalse($this->isActive($userId));
    }

    /**
     * A site manager homed at one of their own sites, and a co-worker there.
     *
     * @return array{0: string, 1: string, 2: string, 3: User} site, the site manager's record, the co-worker, the account
     */
    private function siteManagerAtOwnSite(string $type): array
    {
        $site = $this->workSite();
        [$self, $other] = [$this->employee($type), $this->employee($type)];
        $this->homeSite($self, $site, '2026-01-01');
        $this->homeSite($other, $site, '2026-01-01');

        return [$site, $self, $other, User::findOrFail($this->make($self, $this->body(['site_ids' => [$site]]))->assertCreated()->json('data.site_manager.id'))];
    }

    public function test_a_site_manager_never_lists_their_own_row_on_the_site_day(): void
    {
        [$site, $self, $worker, $manager] = $this->siteManagerAtOwnSite('permanent_worker');
        $this->putDay($self, '2026-08-10', ['site_id' => $site])->assertOk();   // HR records it
        $this->assertContains($self, $this->getJson('/api/v1/attendance/sheet?group=permanent_worker&date=2026-08-10')->assertOk()->json('data.rows.*.employee.id'));
        $this->assertContains($self, $this->getJson("/api/v1/attendance/grid?group=all&date=2026-08-10&site_id={$site}")->assertOk()->json('data.*.employee.id'));

        $this->actingAs($manager, 'api');
        $this->assertSame([$worker], $this->getJson("/api/v1/attendance/site-day?site_id={$site}&date=2026-08-10")->assertOk()->json('data.rows.*.employee.id'));
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $site, 'date' => '2026-08-10', 'rows' => [['employee_id' => $self, 'site_id' => $site, 'in_time' => '08:00']]])
            ->assertNotFound();
    }

    public function test_a_site_manager_never_finds_their_own_record_on_their_other_lists(): void
    {
        $this->leaveTypes();
        [, $self, $worker, $manager] = $this->siteManagerAtOwnSite('permanent_worker');
        foreach ([$self, $worker] as $id) {   // HR files their leave; a notification names each
            $this->postJson('/api/v1/leave/requests', ['employee_id' => $id, 'kind' => 'annual', 'from_date' => '2026-08-20', 'to_date' => '2026-08-20'])->assertCreated();
            app(Notifier::class)->notifyOnce((string) $manager->id, 'leave_decided', '2026-08-10', ['employee_id' => $id], $id);
        }

        $this->actingAs($manager, 'api');
        $lists = [
            'employees' => $this->getJson('/api/v1/employees')->assertOk()->json('data.*.id'),
            'leave requests' => $this->getJson('/api/v1/leave/requests')->assertOk()->json('data.*.employee.id'),
            'leave balances' => $this->getJson('/api/v1/leave/balances')->assertOk()->json('data.*.id'),
            'team fares' => $this->getJson('/api/v1/team-fares')->assertOk()->json('data.rows.*.employee_id'),
            'notifications' => array_values(array_filter($this->getJson('/api/v1/notifications')->assertOk()->json('data.*.employee.id'))),
        ];
        $this->assertSame(array_fill_keys(array_keys($lists), [$worker]), $lists);
        $this->assertSame(1, $this->getJson('/api/v1/employees/summary')->assertOk()->json('data.permanent_worker'));
    }

    public function test_crew_search_never_finds_the_site_managers_own_record(): void
    {
        [$site, $self, $laborer, $manager] = $this->siteManagerAtOwnSite('day_laborer');
        $this->actingAs($manager, 'api');
        $search = fn (string $q) => $this->getJson('/api/v1/attendance/site-day/laborers?'.http_build_query(['site_id' => $site, 'search' => $q]))->assertOk();

        $search('Laborer-2')->assertJsonPath('data.match.id', $laborer);
        $search('Laborer-1')->assertJsonPath('data.match', null)->assertJsonPath('data.ambiguous', false);
        $this->assertSame([$laborer], $this->getJson("/api/v1/attendance/site-day?site_id={$site}")->assertOk()->json('data.rows.*.employee.id'));
    }
}
