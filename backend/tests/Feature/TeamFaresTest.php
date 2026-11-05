<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class TeamFaresTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-05 10:00');
    }

    private function residence(string $name = 'R-1'): string
    {
        return (string) Residence::create(['name_ar' => $name])->getKey();
    }

    private function fare(string $residence, string $site, string $from, string $amount): string
    {
        return (string) Fare::create(['residence_id' => $residence, 'site_id' => $site, 'effective_from' => $from, 'amount' => $amount])->getKey();
    }

    public function test_a_team_lead_reads_only_fares_of_their_sites(): void
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '30');
        $this->fare($r, $s2, '2026-01-01', '40');
        $lead = $this->managerOf($s1);
        $other = $this->managerOf($s2);

        $this->actingAs($lead, 'api');
        $this->getJson('/api/v1/fares')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $s1);
        $this->getJson("/api/v1/fares?manager_id={$other->id}")->assertOk()->assertJsonCount(0, 'data');

        $this->signIn('ceo');
        $this->getJson('/api/v1/fares')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_hr_filters_fares_by_team_lead(): void
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '30');
        $this->fare($r, $s2, '2026-01-01', '40');
        $lead = $this->managerOf($s1);

        $this->signIn('hr');
        $this->getJson("/api/v1/fares?manager_id={$lead->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $s1);
        $this->getJson('/api/v1/fares?manager_id=nope')->assertStatus(422);
    }

    public function test_fares_list_by_site_name_then_residence_name_then_date(): void
    {
        [$b, $a] = [$this->workSite('Site B'), $this->workSite('Site A')];
        [$r2, $r1] = [$this->residence('Res 2'), $this->residence('Res 1')];
        $this->fare($r2, $b, '2026-01-01', '10');
        $this->fare($r1, $a, '2026-02-01', '20');
        $this->fare($r2, $a, '2026-01-01', '30');
        $this->fare($r1, $a, '2026-01-01', '40');

        $this->signIn('hr');
        $this->assertSame(['40.000000', '20.000000', '30.000000', '10.000000'], array_column($this->getJson('/api/v1/fares')->assertOk()->json('data'), 'amount'));
    }

    public function test_fares_carry_set_by(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->fare($r, $s1, '2026-10-01', '30');
        $hr = $this->signIn('hr');
        $this->postJson('/api/v1/fares', ['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-01', 'amount' => '35'])->assertCreated();

        $this->getJson('/api/v1/fares?date=2026-11-05')->assertOk()
            ->assertJsonPath('data.0.set_by.id', $hr->id)
            ->assertJsonPath('data.0.set_by.name', $hr->name)
            ->assertJsonMissingPath('data.0.previous_amount')
            ->assertJsonMissingPath('data.0.set_by_id');
        $this->getJson('/api/v1/fares?date=2026-10-15')->assertOk()
            ->assertJsonPath('data.0.set_by', null);
    }

    public function test_starting_fares_read_as_from_excel(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $importer = $this->signIn('hr');
        $fare = $this->fare($r, $s1, '2026-10-01', '30');
        $override = (string) EmployeeFareOverride::create(['employee_id' => $this->employee('day_laborer'), 'site_id' => $s1, 'effective_from' => '2026-10-01', 'amount' => '20'])->getKey();
        $this->assertSame($importer->id, Fare::find($fare)?->updated_by);

        (require database_path('migrations/2026_11_05_000002_mark_starting_fares_from_excel.php'))->up();

        $this->getJson('/api/v1/fares?date=2026-11-05')->assertOk()->assertJsonPath('data.0.set_by', null);
        $this->assertNull(EmployeeFareOverride::find($override)?->created_by);
        $this->assertNull(EmployeeFareOverride::find($override)?->updated_by);
    }

    private function describe(string $employee, string $site): TestResponse
    {
        return $this->getJson("/api/v1/employees/{$employee}/fare?site_id={$site}&date=2026-11-05")->assertOk();
    }

    public function test_an_engineer_never_takes_the_residence_default(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '40');
        $this->signIn('hr');
        $engineer = $this->employee('engineer', '2026-01-01', ['residence_id' => $r]);

        $this->describe($engineer, $s1)->assertJsonPath('data.source', 'none')->assertJsonPath('data.amount', '0.000000')->assertJsonPath('data.default_amount', null);
        $this->postJson("/api/v1/employees/{$engineer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-01', 'amount' => '25'])->assertCreated();
        $this->describe($engineer, $s1)->assertJsonPath('data.source', 'override')->assertJsonPath('data.amount', '25.000000');
    }

    /** @return array{0: string, 1: string, 2: string, 3: User, 4: string} s1, s2, residence, the lead of s1, a permanent worker homed on s1 */
    private function team(): array
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->signIn('hr');
        $worker = $this->employee('permanent_worker', '2026-01-01', ['residence_id' => $r]);
        $this->homeSite($worker, $s1, '2026-01-01');

        return [$s1, $s2, $r, $this->managerOf($s1), $worker];
    }

    /** @param  array<string, string>  $body */
    private function postFare(array $body): TestResponse
    {
        return $this->postJson('/api/v1/fares', $body);
    }

    public function test_a_team_lead_reads_an_employee_fare_only_at_their_sites(): void
    {
        [$s1, $s2, , $lead] = $this->team();
        $laborer = $this->employee('day_laborer');
        $this->homeSite($laborer, $s1, '2026-01-01');
        $this->actingAs($lead, 'api');

        $this->getJson("/api/v1/employees/{$laborer}/fare?site_id={$s2}")->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
        $this->describe($laborer, $s1)->assertJsonPath('data.source', 'no_residence')->assertJsonPath('data.amount', '0.000000');
    }

    public function test_a_locked_period_refuses_a_write(): void
    {
        [$s1, , $r, , $worker] = $this->team();
        $laborer = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $r]);
        $this->leaveTypes();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-11-01', 'confirm_missing' => true])->assertCreated();

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postJson("/api/v1/employees/{$laborer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->assertSame([0, 0], [Fare::count(), EmployeeFareOverride::count()]);
        // No permanent-worker period is locked.
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated();
    }

    public function test_a_write_dated_before_a_locked_period_is_refused(): void
    {
        [$s1, , $r] = $this->team();
        $laborer = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $r]);
        $newcomer = $this->employee('day_laborer', '2026-11-16');
        $this->leaveTypes();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-11-01', 'confirm_missing' => true])->assertCreated();

        // With no later row, a fare dated 10-06 would govern the locked 11-01..11-15.
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-10-06', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postJson("/api/v1/employees/{$laborer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-10-06', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        // No type on 11-05: every type's locks count.
        $this->postJson("/api/v1/employees/{$newcomer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->assertSame([0, 0], [Fare::count(), EmployeeFareOverride::count()]);

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-16', 'amount' => '30'])->assertCreated();
    }

    public function test_the_option_a_team_lead_grant_is_dropped(): void
    {
        $id = (string) Str::uuid7();
        DB::table('permissions')->insert(['id' => $id, 'resource' => 'fare', 'action' => 'manage', 'scope' => 'own', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('slug', 'manager')->value('id'), 'permission_id' => $id]);
        $migration = require database_path('migrations/2026_11_05_000003_drop_fare_manage_own.php');

        $migration->up();
        $migration->up();   // a re-run matches nothing

        $this->assertFalse(DB::table('permissions')->where('id', $id)->exists());
        $this->assertFalse(DB::table('role_permissions')->where('permission_id', $id)->exists());
        $this->assertTrue(DB::table('permissions')->where(['resource' => 'fare', 'action' => 'manage', 'scope' => 'all'])->exists());
    }

    public function test_a_team_lead_reads_their_team_and_writes_nothing(): void
    {
        [$s1, $s2, $r, $lead, $worker] = $this->team();
        $other = $this->managerOf($s2);
        $this->actingAs($lead, 'api');

        $this->getJson("/api/v1/team-fares?manager_id={$other->id}")->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.employee_id', $worker);
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertForbidden();
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertForbidden();
        $this->assertSame([0, 0], [Fare::count(), EmployeeFareOverride::count()]);
    }

    public function test_hr_fills_a_residence_and_the_pair_fare_applies(): void
    {
        $s1 = $this->workSite('S-1');
        [$r, $r2] = [$this->residence('R-1'), $this->residence('R-2')];
        $this->fare($r, $s1, '2026-01-01', '35');
        $this->signIn('hr');
        $lead = $this->managerOf($s1);
        [$first, $second] = [$this->employee('day_laborer'), $this->employee('day_laborer')];
        $this->homeSite($first, $s1, '2026-01-01');
        $this->homeSite($second, $s1, '2026-01-01');
        $team = fn () => $this->getJson("/api/v1/team-fares?manager_id={$lead->id}")->assertOk();

        $team()->assertJsonPath('data.rows.0.source', 'no_residence');
        $this->patchJson("/api/v1/employees/{$first}", ['residence_id' => $r])->assertOk();
        $this->patchJson("/api/v1/employees/{$second}", ['residence_id' => $r2])->assertOk();
        $team()->assertJsonPath('data.rows.0.source', 'default')->assertJsonPath('data.rows.0.amount', '35.000000')
            ->assertJsonPath('data.rows.1.source', 'none')->assertJsonPath('data.rows.1.amount', null);
    }

    public function test_same_key_updates_in_place_and_is_audited(): void
    {
        [$s1, , $r, , $worker] = $this->team();

        $id = $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated()->json('data.id');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '35'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Fare::count());
        $this->assertSame('35.000000', Fare::findOrFail($id)->amount);
        $this->assertAudited('fares', $id);

        $url = "/api/v1/employees/{$worker}/fare-overrides";
        $oid = $this->postJson($url, ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated()->json('data.id');
        $this->postJson($url, ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '35'])->assertCreated()->assertJsonPath('data.amount', '35.000000');
        $this->assertSame(1, EmployeeFareOverride::count());
        $this->assertAudited('employee_fare_overrides', $oid);
    }

    private function assertAudited(string $table, string $id): void
    {
        $rows = DB::table('audit_log')->where(['entity_type' => $table, 'entity_id' => $id])->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED'], $rows->pluck('event')->all());
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[0]->new_values, true)['amount'], '30', 6));
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[1]->old_values, true)['amount'], '30', 6));
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[1]->new_values, true)['amount'], '35', 6));
    }

    public function test_a_concurrent_first_write_of_one_key_updates_it(): void
    {
        [$s1, , $r] = $this->team();
        $key = ['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05'];
        // The rival commits between the save's existence check and its insert.
        $raced = false;
        DB::listen(function (QueryExecuted $q) use ($key, &$raced): void {
            if (! $raced && str_starts_with($q->sql, 'select * from "fares"') && str_contains($q->sql, '"effective_from" = ?')) {
                $raced = true;
                DB::table('fares')->insert([...$key, 'id' => (string) Str::uuid7(), 'amount' => '30']);
            }
        });

        $this->postFare([...$key, 'amount' => '35'])->assertCreated()->assertJsonPath('data.amount', '35.000000');
        $this->assertTrue($raced);
        $this->assertSame('35.000000', Fare::sole()->amount);
    }

    public function test_team_fares_lists_the_team(): void
    {
        [$s1, $s2, $s3] = [$this->workSite('S-1'), $this->workSite('S-2'), $this->workSite('S-3')];
        [$r, $r2] = [$this->residence('R-1'), $this->residence('R-2')];
        $this->fare($r, $s1, '2026-01-01', '35');
        $this->signIn('hr');
        $lead = $this->managerOf($s1, $s3);
        $other = $this->managerOf($s2);
        $homed = function (string $type, ?string $residence, string $site, array $more = []): string {
            $id = $this->employee($type, '2026-01-01', ['residence_id' => $residence, ...$more]);
            $this->homeSite($id, $site, '2026-01-01');

            return $id;
        };
        $byDefault = $homed('permanent_worker', $r, $s1);
        $overridden = $homed('day_laborer', $r, $s1);
        EmployeeFareOverride::create(['employee_id' => $overridden, 'site_id' => $s1, 'effective_from' => '2026-01-01', 'amount' => '30']);
        $homed('day_laborer', null, $s1);
        $homed('permanent_worker', $r2, $s1);
        $engineer = $this->employee('engineer', '2026-01-01', ['residence_id' => $r]);
        app(EmployeeService::class)->assignManager($engineer, ['manager_id' => $lead->id, 'effective_from' => '2026-01-01']);
        EmployeeFareOverride::create(['employee_id' => $engineer, 'site_id' => $s1, 'effective_from' => '2026-01-01', 'amount' => '50']);
        EmployeeFareOverride::create(['employee_id' => $engineer, 'site_id' => $s2, 'effective_from' => '2026-01-01', 'amount' => '60']);
        $homed('permanent_worker', $r, $s2);
        $homed('permanent_worker', $r, $s1, ['termination_date' => '2026-10-31']);

        $this->actingAs($lead, 'api');
        $res = $this->getJson('/api/v1/team-fares')->assertOk()
            ->assertJsonPath('data.sites', [['id' => $s1, 'name' => 'S-1'], ['id' => $s3, 'name' => 'S-3']])
            ->assertJsonCount(5, 'data.rows')
            ->assertJsonPath('data.rows.0.employee_id', $byDefault)
            ->assertJsonPath('data.rows.0.type', 'permanent_worker')
            ->assertJsonPath('data.rows.0.residence', ['id' => $r, 'name' => 'R-1'])
            ->assertJsonPath('data.rows.0.site', ['id' => $s1, 'name' => 'S-1'])
            ->assertJsonPath('data.rows.0.amount', '35.000000')
            ->assertJsonPath('data.rows.0.source', 'default')
            ->assertJsonPath('data.rows.0.default_amount', '35.000000')
            ->assertJsonPath('data.rows.0.overrides', [])
            ->assertJsonPath('data.rows.1.employee_id', $overridden)
            ->assertJsonPath('data.rows.1.amount', '30.000000')
            ->assertJsonPath('data.rows.1.source', 'override')
            ->assertJsonPath('data.rows.1.default_amount', '35.000000')
            ->assertJsonPath('data.rows.2.residence', null)
            ->assertJsonPath('data.rows.2.amount', null)
            ->assertJsonPath('data.rows.2.source', 'no_residence')
            ->assertJsonPath('data.rows.3.residence.name', 'R-2')
            ->assertJsonPath('data.rows.3.amount', null)
            ->assertJsonPath('data.rows.3.source', 'none')
            ->assertJsonPath('data.rows.3.default_amount', null)
            ->assertJsonPath('data.rows.4.employee_id', $engineer)
            ->assertJsonPath('data.rows.4.type', 'engineer')
            ->assertJsonPath('data.rows.4.site', null)
            ->assertJsonPath('data.rows.4.amount', null)
            ->assertJsonPath('data.rows.4.source', 'none')
            ->assertJsonPath('data.rows.4.default_amount', null)
            ->assertJsonPath('data.rows.4.overrides', [['site_id' => $s1, 'amount' => '50.000000']]);
        $this->assertIsInt($res->json('data.rows.0.code'));
        $this->assertSame('Worker-1', $res->json('data.rows.0.name'));
        $this->getJson("/api/v1/team-fares?manager_id={$other->id}")->assertOk()->assertJsonCount(5, 'data.rows');

        $this->signIn('hr');
        $this->getJson('/api/v1/team-fares')->assertStatus(422)->assertJsonPath('error.details.0.field', 'manager_id');
        $this->getJson("/api/v1/team-fares?manager_id={$lead->id}&date=2026-11-05")->assertOk()->assertJsonCount(5, 'data.rows')
            ->assertJsonPath('data.rows.4.employee_id', $engineer);
        $this->signIn('ceo');
        $this->getJson('/api/v1/team-fares')->assertStatus(422)->assertJsonPath('error.details.0.field', 'manager_id');
        $this->getJson("/api/v1/team-fares?manager_id={$lead->id}")->assertOk()->assertJsonCount(5, 'data.rows');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertForbidden();
    }

    public function test_team_fares_name_a_deleted_residence_and_site(): void
    {
        [$s1, , $r, $lead] = $this->team();
        Residence::findOrFail($r)->delete();
        Site::findOrFail($s1)->delete();
        $this->actingAs($lead, 'api');

        $this->getJson('/api/v1/team-fares')->assertOk()
            ->assertJsonPath('data.sites', [])
            ->assertJsonPath('data.rows.0.residence', ['id' => $r, 'name' => 'R-1'])
            ->assertJsonPath('data.rows.0.site', ['id' => $s1, 'name' => 'S-1']);
    }

    public function test_team_fares_list_a_day_crew_laborer_with_no_home_site_or_a_home_site_elsewhere(): void
    {
        [$s1, $s2, $r, $lead] = $this->team();
        $proposed = $this->employee('day_laborer', '2026-11-05');                            // added by the site manager: no home site, no residence
        DayCrewChange::create(['employee_id' => $proposed, 'date' => '2026-11-05', 'site_id' => $s1]);
        $loaned = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $r]);     // homed at S-2, on S-1's crew today
        $this->homeSite($loaned, $s2, '2026-01-01');
        DayCrewChange::create(['employee_id' => $loaned, 'date' => '2026-11-05', 'site_id' => $s1]);
        $this->actingAs($lead, 'api');

        $rows = collect($this->getJson('/api/v1/team-fares')->assertOk()->assertJsonCount(3, 'data.rows')->json('data.rows'))->keyBy('employee_id');
        $this->assertSame(['site' => null, 'amount' => null, 'source' => 'no_residence'], array_intersect_key($rows[$proposed], array_flip(['site', 'amount', 'source'])));
        $this->assertSame(['id' => $s2, 'name' => 'S-2'], $rows[$loaned]['site']);
    }
}
