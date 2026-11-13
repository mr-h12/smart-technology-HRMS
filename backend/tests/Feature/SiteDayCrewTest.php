<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Today's day-labor crew on a site manager's site, and the day laborers they propose (owner, 2026-11-04). */
class SiteDayCrewTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private string $s3;

    private User $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        [$this->s1, $this->s2, $this->s3] = [$this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->managerOf($this->s3);
    }

    public function test_search_finds_one_laborer_by_code_or_full_name_and_never_lists_the_company(): void
    {
        $free = $this->employee('day_laborer');                                        // Laborer-1, no site
        $this->homeSite($mine = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->homeSite($theirs = $this->employee('day_laborer'), $this->s3, '2026-10-01');
        $this->homeSite($worker = $this->employee('permanent_worker'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');

        $this->assertSame([$free, null, true], $this->found('Laborer-1', ['id', 'site', 'can_add']));
        $this->assertSame([$mine, 'Site-2', true], $this->found('  Laborer-2 ', ['id', 'site.name_ar', 'can_add']));   // spaces do not matter
        $this->assertSame([$theirs, 'Site-3', false], $this->found((string) $this->code($theirs), ['id', 'site.name_ar', 'can_add']));
        foreach (['Labor', 'Laborer', 'aborer-1', (string) $this->code($worker)] as $partOrOther) {      // part of a name, or not a day laborer
            $this->search($partOrOther)->assertJsonPath('data.match', null)->assertJsonPath('data.ambiguous', false);
        }
        $this->assertStringNotContainsString('wage', $this->search('Laborer-1')->getContent());

        DB::table('employees')->where('id', $mine)->update(['name_ar' => 'Laborer-1']);               // two share the name: the code tells them apart
        $this->search('Laborer-1')->assertJsonPath('data.match', null)->assertJsonPath('data.ambiguous', true);
        $this->assertSame([$mine], $this->found((string) $this->code($mine), ['id']));
    }

    public function test_add_and_remove_change_today_only_and_keep_yesterday_and_tomorrow(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-21', 'rows' => [['employee_id' => $l, 'site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00']]])->assertOk();

        $this->crew($this->s1, $l, 'remove')->assertOk();
        $this->assertNotContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertContains($l, $this->roster($this->s1, '2026-10-21'));                            // yesterday's list and day stay
        $this->assertSame('16:00:00', DB::table('attendance_days')->where(['employee_id' => $l, 'date' => '2026-10-21'])->value('out_time'));
        $this->assertSame(1, DB::table('employee_site_assignments')->where('employee_id', $l)->count());   // the home site is untouched
        $this->assertNull(DB::table('employees')->where('id', $l)->value('deleted_at'));
        $this->assertSame(0, DB::table('attendance_days')->where(['employee_id' => $l, 'date' => '2026-10-22'])->count());   // not an absence

        $this->cairo('2026-10-23 09:00');
        $this->assertContains($l, $this->roster($this->s1, '2026-10-23'));                            // tomorrow: the normal assignment
        $this->cairo('2026-10-22 09:00');

        $this->crew($this->s1, $l, 'add')->assertOk();                                                // back home: the day's change goes
        $this->assertContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertSame(0, DB::table('day_crew_changes')->where('employee_id', $l)->whereNull('deleted_at')->count());
    }

    public function test_moving_a_laborer_between_the_managers_sites_holds_for_today_and_their_day_can_be_saved(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->crew($this->s1, $l, 'add')->assertOk();
        $this->assertContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertNotContains($l, $this->roster($this->s2, '2026-10-22'));
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $l, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();

        $this->cairo('2026-10-23 09:00');
        $this->assertNotContains($l, $this->roster($this->s1, '2026-10-23'));
        $this->assertContains($l, $this->roster($this->s2, '2026-10-23'));
    }

    public function test_a_laborer_with_a_day_saved_at_another_site_is_not_moved(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s2, 'date' => '2026-10-22', 'rows' => [['employee_id' => $l, 'site_id' => $this->s2, 'in_time' => '08:00']]])->assertOk();
        $this->crew($this->s1, $l, 'add')->assertStatus(422)->assertJsonPath('error.code', 'crew_moved_has_day')->assertJsonPath('error.details.0.field', 'employee_id');
        $this->assertContains($l, $this->roster($this->s2, '2026-10-22'));
    }

    public function test_the_rules_on_adding_and_removing(): void
    {
        $this->homeSite($theirs = $this->employee('day_laborer'), $this->s3, '2026-10-01');
        $this->homeSite($worker = $this->employee('permanent_worker'), $this->s1, '2026-10-01');
        $this->homeSite($recorded = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');

        $this->crew($this->s1, $theirs, 'add')->assertStatus(422)->assertJsonPath('error.code', 'crew_on_other_site')->assertJsonPath('error.details.0.field', 'employee_id');
        $this->crew($this->s1, $worker, 'add')->assertNotFound();                                     // only day laborers
        $this->crew($this->s1, $worker, 'remove')->assertNotFound();
        $this->crew($this->s3, $theirs, 'remove')->assertNotFound();                                  // not this manager's site
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $recorded, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();
        $this->crew($this->s1, $recorded, 'remove')->assertStatus(422)->assertJsonPath('error.code', 'crew_has_day');   // clear today's day first
    }

    public function test_a_proposed_laborer_joins_today_with_no_pay_row_until_hr_approves(): void
    {
        $this->actingAs($this->m, 'api');
        $this->propose(['gender' => null])->assertStatus(422)->assertJsonPath('error.details.0.field', 'gender');   // the gender is required
        $id = $this->propose(['phone' => '01012345678'])->assertCreated()->json('data.id');
        $created = DB::table('audit_log')->where('event', 'CREATED')->where(fn ($q) => $q->where('entity_id', $id)->orWhereRaw("new_values->>'employee_id' = ?", [$id]))->pluck('entity_type')->all();
        $this->assertEqualsCanonicalizing(['employees', 'employee_type_changes', 'day_crew_changes', 'employee_wage_proposals'], $created);
        $this->assertSame('female', DB::table('employees')->where('id', $id)->value('gender'));
        $this->assertContains($id, $this->roster($this->s1, '2026-10-22'));
        $this->assertSame(0, DB::table('employee_pay_changes')->where('employee_id', $id)->count());
        $this->assertSame(0, DB::table('employee_site_assignments')->where('employee_id', $id)->count());     // today's crew only
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $id, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();

        $proposal = DB::table('employee_wage_proposals')->where('employee_id', $id)->first();
        $this->assertSame(['pending', '350.000000'], [$proposal->status, $proposal->daily_wage]);
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1])->assertForbidden();   // the site manager
        $this->getJson("/api/v1/employees/{$id}")->assertOk()->assertJsonMissingPath('data.current_pay');

        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1, 'daily_wage' => '340'])->assertOk();
        $this->assertSame(['2026-10-22', '340.000000'], [
            (string) DB::table('employee_pay_changes')->where('employee_id', $id)->value('effective_from'),
            (string) DB::table('employee_pay_changes')->where('employee_id', $id)->value('daily_wage'),
        ]);
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1])->assertStatus(409);
        $this->assertSame(['pending', 'approved'], DB::table('audit_log')->where(['entity_type' => 'employee_wage_proposals', 'entity_id' => $proposal->id])->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (object $a) => json_decode((string) $a->new_values, true)['status'] ?? null)->all());
    }

    public function test_a_refused_laborer_ends_that_day_leaves_every_crew_and_keeps_their_recorded_days(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose()->assertCreated()->json('data');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $made['id'], 'site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00']]])->assertOk();

        $this->cairo('2026-10-24 10:00');                                                               // HR decides two days later
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame('2026-10-24', (string) DB::table('employees')->where('id', $made['id'])->value('termination_date'));
        $this->assertSame(0, DB::table('employee_pay_changes')->where('employee_id', $made['id'])->count());
        $this->assertSame(1, DB::table('attendance_days')->where(['employee_id' => $made['id'], 'date' => '2026-10-22'])->whereNull('deleted_at')->count());   // HR decides on it
        $this->assertTrue(DB::table('audit_log')->where(['entity_type' => 'employees', 'entity_id' => $made['id'], 'event' => 'UPDATED'])->exists());
        $this->getJson('/api/v1/employees/wage-proposals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 2])->assertStatus(409);

        $this->actingAs($this->m, 'api');
        $this->assertNotContains($made['id'], $this->roster($this->s1, '2026-10-24'));
        $this->search((string) $made['code'])->assertJsonPath('data.match', null);
        $this->crew($this->s1, $made['id'], 'add')->assertNotFound();
        $this->crew($this->s2, $made['id'], 'add')->assertNotFound();
    }

    public function test_a_laborer_refused_on_their_first_day_leaves_that_days_crew(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose()->assertCreated()->json('data');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse", ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('day_crew_changes')->where('employee_id', $made['id'])->whereNull('deleted_at')->count());
        $this->actingAs($this->m, 'api');
        $this->assertNotContains($made['id'], $this->roster($this->s1, '2026-10-22'));
        $this->crew($this->s1, $made['id'], 'add')->assertNotFound();
    }

    public function test_hr_hears_of_a_proposed_wage_at_once_and_reads_the_list_a_page_at_a_time(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($this->m, 'api');
        $made = $this->propose()->assertCreated()->json('data');
        $n = DB::table('notifications')->where(['user_id' => $hr->id, 'type' => 'wage_proposed'])->first();
        $this->assertNotNull($n);
        $this->assertSame($made['proposal_id'], $n->ref);
        $payload = json_decode($n->data, true);
        $this->assertSame([$made['id'], 'Site-1'], [$payload['employee_id'], $payload['site_name_ar']]);
        $this->assertArrayNotHasKey('daily_wage', $payload);
        // A legacy inbox row must not return an amount, including from the mark-read response.
        DB::table('notifications')->where('id', $n->id)->update(['data' => json_encode([...$payload, 'daily_wage' => '350'])]);
        $this->actingAs($hr, 'api');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.ref', $made['proposal_id'])
            ->assertJsonMissingPath('data.0.data.daily_wage');
        $this->patchJson("/api/v1/notifications/{$n->id}/read")->assertOk()->assertJsonMissingPath('data.data.daily_wage');
        $this->actingAs($this->m, 'api');
        $this->propose(['name_ar' => 'عامل آخر', 'daily_wage' => '300'])->assertCreated();

        $this->actingAs($hr, 'api');
        $this->getJson('/api/v1/employees/wage-proposals?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.has_next_page', true);
        // each row names the site the laborer was added to and the site manager who proposed the wage, oldest first
        $this->getJson('/api/v1/employees/wage-proposals')->assertOk()
            ->assertJsonPath('data.0.id', $made['proposal_id'])
            ->assertJsonPath('data.0.daily_wage', '350.000000')
            ->assertJsonPath('data.0.site.name_ar', 'Site-1')
            ->assertJsonPath('data.0.proposed_by', ['id' => (string) $this->m->id, 'name' => $this->m->name])
            ->assertJsonPath('data.0.employee.hire_date', '2026-10-22')
            ->assertJsonMissingPath('data.0.site_id');
    }

    public function test_crew_and_wage_changes_cannot_mutate_a_locked_day_labor_period(): void
    {
        $this->leaveTypes();
        $this->homeSite($laborer = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $made = $this->propose()->assertCreated()->json('data');

        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-10-16', 'confirm_missing' => true])->assertCreated();
        foreach (['approve', 'refuse'] as $action) {
            $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/{$action}", ['version' => 1])
                ->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        }
        $this->assertDatabaseHas('employee_wage_proposals', ['id' => $made['proposal_id'], 'status' => 'pending']);
        $this->assertDatabaseMissing('employee_pay_changes', ['employee_id' => $made['id']]);

        $this->actingAs($this->m, 'api');
        $this->crew($this->s1, $laborer, 'remove')->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        $this->crew($this->s2, $laborer, 'add')->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        $this->propose(['name_ar' => 'عامل آخر'])->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        $this->assertDatabaseMissing('day_crew_changes', ['employee_id' => $laborer, 'date' => '2026-10-22']);
        $this->assertDatabaseMissing('employees', ['name_ar' => 'عامل آخر']);
    }

    public function test_hr_refuses_a_proposed_wage_and_it_leaves_the_list_with_no_pay_row(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose()->assertCreated()->json('data');

        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame(0, DB::table('employee_pay_changes')->where('employee_id', $made['id'])->count());
        $this->getJson('/api/v1/employees/wage-proposals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 2])->assertStatus(409);   // decided
        $this->getJson("/api/v1/employees/{$made['id']}")->assertOk();   // the laborer stays on file
    }

    public function test_the_evening_alert_counts_the_days_crew_changes(): void
    {
        $hr = $this->makeUser('hr');
        $this->homeSite($l = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->homeSite($k = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->crew($this->s1, $l, 'remove')->assertOk();
        $this->crew($this->s1, $k, 'add')->assertOk();

        $this->artisan('attendance:alert-missing', ['date' => '2026-10-22'])->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'crew_changes')->count());
        $groups = collect(json_decode((string) DB::table('notifications')->where(['user_id' => $hr->id, 'type' => 'attendance_missing_days'])->value('data'), true)['groups']);
        $this->assertSame(2, $groups->firstWhere('period_start', '2026-10-16')['crew_changes']);       // the day laborers' current period
        $this->assertSame(0, $groups->firstWhere('period_start', '2026-10-01')['crew_changes']);
    }

    public function test_a_site_manager_cannot_create_engineers_or_permanent_workers_or_set_pay(): void
    {
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/employees', ['name_ar' => 'X', 'gender' => 'male', 'type' => 'permanent_worker', 'effective_from' => '2026-10-22', 'salary' => '9000'])->assertForbidden();
        $this->postJson('/api/v1/employees', ['name_ar' => 'X', 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-10-22', 'salary' => '9000'])->assertForbidden();
        $this->propose(['daily_wage' => '0'])->assertStatus(422);
        $this->propose(['site_id' => $this->s3])->assertNotFound();
        $this->propose(['type' => 'engineer'])->assertCreated();
        $this->assertSame('day_laborer', DB::table('employee_type_changes')->orderByDesc('id')->value('type'));   // the body's type is ignored
        $this->getJson('/api/v1/employees/wage-proposals')->assertForbidden();
    }

    /** @param  array<string, mixed>  $over */
    private function propose(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد', 'gender' => 'female', 'daily_wage' => '350', ...$over]);
    }

    private function search(string $search): TestResponse
    {
        return $this->getJson('/api/v1/attendance/site-day/laborers?'.http_build_query(['site_id' => $this->s1, 'search' => $search]))->assertOk();
    }

    /**
     * @param  list<string>  $paths  in data.match
     * @return list<mixed>
     */
    private function found(string $search, array $paths): array
    {
        $r = $this->search($search)->assertJsonPath('data.ambiguous', false);

        return array_map(fn (string $p) => $r->json("data.match.{$p}"), $paths);
    }

    private function code(string $employeeId): int
    {
        return (int) DB::table('employees')->where('id', $employeeId)->value('code');
    }

    private function crew(string $siteId, string $employeeId, string $action): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day/crew', ['site_id' => $siteId, 'employee_id' => $employeeId, 'action' => $action]);
    }

    /** @return list<string> */
    private function roster(string $siteId, string $date): array
    {
        return $this->getJson("/api/v1/attendance/site-day?site_id={$siteId}&date={$date}")->assertOk()->json('data.rows.*.employee.id');
    }
}
