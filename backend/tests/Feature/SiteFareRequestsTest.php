<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * The site manager's transport fare screen for one site and their change requests; HR approves (as asked or revised) or refuses with a
 * reason; the fare in force changes only on approval (owner, 2026-11-19).
 */
class SiteFareRequestsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private string $s3;

    private string $res;

    private User $m;

    private User $other;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-20 10:00');
        $this->hr = $this->signIn('hr');
        [$this->s1, $this->s2, $this->s3] = [$this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];
        $this->res = (string) Residence::create(['name_ar' => 'سكن-1'])->getKey();
        Fare::create(['residence_id' => $this->res, 'site_id' => $this->s1, 'effective_from' => '2026-01-01', 'amount' => '30']);
        Fare::create(['residence_id' => $this->res, 'site_id' => $this->s2, 'effective_from' => '2026-01-01', 'amount' => '45.5']);
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->other = $this->managerOf($this->s3);
    }

    private function worker(string $type, string $site, ?string $residence = null): string
    {
        $id = $this->employee($type, '2026-01-01', ['residence_id' => $residence ?? $this->res]);
        $this->homeSite($id, $site, '2026-01-01');

        return $id;
    }

    private function site(string $site): TestResponse
    {
        return $this->getJson("/api/v1/team-fares/site?site_id={$site}");
    }

    /** @param  array<string, mixed>  $over */
    private function ask(string $employee, array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/fare-requests', ['employee_id' => $employee, 'site_id' => $this->s1, 'amount' => '40', 'reason' => 'سكن جديد أبعد', ...$over]);
    }

    /** @return array<string, mixed> */
    private function rowOf(TestResponse $r, string $employee): array
    {
        return collect($r->json('data.rows'))->firstWhere('employee_id', $employee) ?? [];
    }

    public function test_each_site_shows_its_team_with_the_fare_there_and_unset_differs_from_zero(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $over = $this->worker('day_laborer', $this->s1);
        EmployeeFareOverride::create(['employee_id' => $over, 'site_id' => $this->s1, 'effective_from' => '2026-01-01', 'amount' => '0']);
        $homeless = $this->employee('day_laborer');
        $this->homeSite($homeless, $this->s1, '2026-01-01');
        $atS2 = $this->worker('permanent_worker', $this->s2);
        $engineer = $this->employee('engineer', '2026-01-01', ['residence_id' => $this->res]);
        app(EmployeeService::class)->assignManager($engineer, ['manager_id' => $this->m->id, 'effective_from' => '2026-01-01']);
        EmployeeFareOverride::create(['employee_id' => $engineer, 'site_id' => $this->s2, 'effective_from' => '2026-01-01', 'amount' => '60']);
        $this->actingAs($this->m, 'api');

        $one = $this->site($this->s1)->assertOk()->assertJsonPath('data.site.id', $this->s1)->assertJsonCount(2, 'data.sites');
        $this->assertEqualsCanonicalizing([$worker, $over, $homeless, $engineer], array_column($one->json('data.rows'), 'employee_id'));
        $this->assertSame(['amount' => '30.000000', 'source' => 'default'], array_intersect_key($this->rowOf($one, $worker), ['amount' => 1, 'source' => 1]));
        $this->assertSame(['amount' => '0.000000', 'source' => 'override'], array_intersect_key($this->rowOf($one, $over), ['amount' => 1, 'source' => 1]));   // set to zero
        $this->assertSame(['amount' => null, 'source' => 'no_residence'], array_intersect_key($this->rowOf($one, $homeless), ['amount' => 1, 'source' => 1]));  // unset
        $this->assertSame(['amount' => null, 'source' => 'none'], array_intersect_key($this->rowOf($one, $engineer), ['amount' => 1, 'source' => 1]));          // no override here
        $this->assertNull($this->rowOf($one, $worker)['pending']);

        $two = $this->site($this->s2)->assertOk();   // switching site: its people, its amounts
        $this->assertEqualsCanonicalizing([$atS2, $engineer], array_column($two->json('data.rows'), 'employee_id'));
        $this->assertSame('45.500000', $this->rowOf($two, $atS2)['amount']);
        $this->assertSame('60.000000', $this->rowOf($two, $engineer)['amount']);
        $this->assertArrayNotHasKey('salary', $this->rowOf($two, $atS2));   // no pay or other sensitive field on the screen

        $this->site($this->s3)->assertNotFound();   // another manager's site
        $this->getJson('/api/v1/team-fares/site?site_id='.Str::uuid7())->assertNotFound();
    }

    public function test_the_fare_in_force_holds_until_hr_approves_and_then_is_the_approved_amount_from_hrs_date(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $this->actingAs($this->m, 'api');
        $req = $this->ask($worker, ['amount' => '٤٢٫٧٥', 'client_request_id' => (string) Str::uuid7()])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.outcome', 'pending')
            ->assertJsonPath('data.current_amount', '30.000000')->assertJsonPath('data.requested_amount', '42.750000')
            ->assertJsonPath('data.approved_amount', null)->assertJsonMissingPath('data.requested_by')->json('data');
        $row = $this->rowOf($this->site($this->s1), $worker);
        $this->assertSame('30.000000', $row['amount']);                                   // still the approved fare
        $this->assertSame(['id' => $req['id'], 'requested_amount' => '42.750000', 'created_at' => $req['created_at'], 'mine' => true], $row['pending']);

        $this->actingAs($this->hr, 'api');
        $this->getJson('/api/v1/fare-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.requested_by.id', $this->m->id)
            ->assertJsonPath('data.0.employee.id', $worker)->assertJsonPath('data.0.site.name_ar', 'Site-1');
        $this->assertSame(1, DB::table('notifications')->where(['user_id' => $this->hr->id, 'type' => 'fare_requested', 'ref' => $req['id']])->count());
        $data = json_decode((string) DB::table('notifications')->where('type', 'fare_requested')->value('data'), true);
        $this->assertEqualsCanonicalizing(['employee_id', 'request_id', 'site_name_ar', 'site_name_en'], array_keys($data));   // no amount (§9)

        $this->postJson("/api/v1/fare-requests/{$req['id']}/approve", ['version' => 1, 'effective_from' => '2026-11-21'])->assertOk()
            ->assertJsonPath('data.outcome', 'approved')->assertJsonPath('data.approved_amount', '42.750000')->assertJsonPath('data.effective_from', '2026-11-21')
            ->assertJsonPath('data.decided_by.id', $this->hr->id);
        $this->assertSame('42.750000', (string) EmployeeFareOverride::where(['employee_id' => $worker, 'site_id' => $this->s1, 'effective_from' => '2026-11-21'])->value('amount'));

        $this->actingAs($this->m, 'api');
        $this->assertSame('30.000000', $this->rowOf($this->site($this->s1), $worker)['amount']);   // HR's date is tomorrow
        $this->cairo('2026-11-21 08:00');
        $after = $this->rowOf($this->site($this->s1), $worker);
        $this->assertSame(['42.750000', 'override', null], [$after['amount'], $after['source'], $after['pending']]);
        $this->getJson("/api/v1/fare-requests/mine/{$req['id']}")->assertOk()->assertJsonPath('data.outcome', 'approved')->assertJsonPath('data.decided_at', fn ($v) => $v !== null);
        $this->assertSame(1, DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'fare_approved', 'ref' => $req['id']])->count());
    }

    public function test_hr_revises_the_amount_or_refuses_with_a_required_reason_and_the_manager_reads_each_outcome(): void
    {
        [$a, $b] = [$this->worker('day_laborer', $this->s1), $this->worker('permanent_worker', $this->s1)];
        $this->actingAs($this->m, 'api');
        $ra = $this->ask($a, ['amount' => '50'])->assertCreated()->json('data.id');
        $rb = $this->ask($b, ['amount' => '55.5'])->assertCreated()->json('data.id');

        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/fare-requests/{$ra}/approve", ['version' => 1, 'amount' => '٣٥٫٢٥'])->assertOk()
            ->assertJsonPath('data.outcome', 'revised')->assertJsonPath('data.requested_amount', '50.000000')->assertJsonPath('data.approved_amount', '35.250000')
            ->assertJsonPath('data.effective_from', '2026-11-20');                       // today by default
        $this->withHeader('Accept-Language', 'ar')->postJson("/api/v1/fare-requests/{$rb}/refuse", ['version' => 1, 'reason' => '   '])->assertStatus(422)
            ->assertJsonPath('error.details.0.message', __('errors.refusal_reason_required', [], 'ar'));
        $this->postJson("/api/v1/fare-requests/{$rb}/refuse", ['version' => 1, 'reason' => 'نفس السكن والموقع'])->assertOk()
            ->assertJsonPath('data.outcome', 'refused')->assertJsonPath('data.refusal_reason', 'نفس السكن والموقع')->assertJsonPath('data.approved_amount', null);
        $this->assertNull(EmployeeFareOverride::where('employee_id', $b)->first());     // a refusal changes no fare
        $audit = DB::table('audit_log')->where(['entity_type' => 'fare_change_requests', 'entity_id' => $ra])->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $audit->count());                             // the submission and the decision, each with its actor
        $this->assertSame($this->hr->id, (string) $audit->last()->user_id);

        $this->actingAs($this->m, 'api');
        $mine = $this->getJson('/api/v1/fare-requests/mine')->assertOk()->json('data');
        $this->assertSame(['refused', 'revised'], array_column($mine, 'outcome'));
        $this->assertSame('35.250000', $this->rowOf($this->site($this->s1), $a)['amount']);
        $this->assertSame('30.000000', $this->rowOf($this->site($this->s1), $b)['amount']);
        $this->assertSame(1, DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'fare_refused', 'ref' => $rb])->count());
    }

    public function test_a_new_day_laborer_with_no_fare_can_be_asked_for(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد أول', 'gender' => 'male', 'daily_wage' => '350'])
            ->assertCreated()->json('data.id');
        $row = $this->rowOf($this->site($this->s1), $made);
        $this->assertSame([null, 'no_residence'], [$row['amount'], $row['source']]);
        $this->ask($made, ['amount' => '0'])->assertCreated()->assertJsonPath('data.current_amount', null)->assertJsonPath('data.requested_amount', '0.000000');
    }

    public function test_a_retry_answers_the_first_request_and_a_second_pending_one_is_refused_with_it(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $this->actingAs($this->m, 'api');
        $key = (string) Str::uuid7();
        $first = $this->ask($worker, ['client_request_id' => $key])->assertCreated()->json('data.id');
        $this->ask($worker, ['client_request_id' => $key, 'amount' => '40.00'])->assertCreated()->assertJsonPath('data.id', $first);   // the same amount, written another way
        $this->ask($worker, ['client_request_id' => $key, 'amount' => '99'])->assertStatus(409)->assertJsonPath('error.code', 'client_request_reused');   // a correction is never answered as saved
        $this->ask($worker, ['amount' => '41'])->assertStatus(409)->assertJsonPath('error.code', 'fare_request_pending')
            ->assertJsonPath('error.pending.id', $first)->assertJsonPath('error.pending.mine', true);
        $this->ask($worker, ['site_id' => $this->s2])->assertStatus(404);              // not on Site-2's list today
        $this->assertSame(1, DB::table('fare_change_requests')->count());

        $this->actingAs($this->other, 'api');
        $this->postJson('/api/v1/fare-requests', ['employee_id' => $worker, 'site_id' => $this->s1, 'amount' => '1', 'reason' => 'x', 'client_request_id' => $key])->assertNotFound();
    }

    public function test_two_hr_decisions_never_both_land(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $this->actingAs($this->m, 'api');
        $id = $this->ask($worker)->assertCreated()->json('data.id');
        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 1])->assertOk();
        $this->postJson("/api/v1/fare-requests/{$id}/refuse", ['version' => 1, 'reason' => 'late'])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 2])->assertStatus(409);
        $this->assertSame(1, EmployeeFareOverride::where('employee_id', $worker)->count());
    }

    public function test_amounts_are_exact_decimals_and_bad_ones_are_refused(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $this->actingAs($this->m, 'api');
        foreach (['-5', '1.1234567', 'abc', '1e3', '١٢,٥'] as $bad) {
            $this->ask($worker, ['amount' => $bad])->assertStatus(422)->assertJsonPath('error.details.0.field', 'amount');
        }
        $this->ask($worker, ['reason' => '  '])->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');
        $this->ask($worker, ['amount' => '۱۲۳۴۵۶٫۱۲۳۴۵۶'])->assertCreated()->assertJsonPath('data.requested_amount', '123456.123456');
    }

    public function test_scope_and_permissions_are_enforced_on_altered_ids(): void
    {
        $mine = $this->worker('permanent_worker', $this->s1);
        $theirs = $this->worker('permanent_worker', $this->s3);
        $this->actingAs($this->m, 'api');
        $this->ask($theirs)->assertNotFound();                                          // not on Site-1's list
        $this->ask($theirs, ['site_id' => $this->s3])->assertNotFound();                // not this manager's site
        $id = $this->ask($mine)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/fare-requests')->assertForbidden();                     // HR's list
        $this->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 1])->assertForbidden();
        $this->getJson("/api/v1/fare-requests/{$id}")->assertNotFound();   // no such route: HR reads its queue (review of #76)

        $this->actingAs($this->other, 'api');
        $this->getJson("/api/v1/fare-requests/mine/{$id}")->assertNotFound();
        $this->getJson('/api/v1/fare-requests/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->site($this->s1)->assertNotFound();

        $this->actingAs($this->hr, 'api');
        $this->postJson('/api/v1/fare-requests', ['employee_id' => $mine, 'site_id' => $this->s1, 'amount' => '1', 'reason' => 'x'])->assertForbidden();   // HR sets fares directly
        $this->getJson("/api/v1/team-fares/site?site_id={$this->s1}")->assertForbidden();   // the per-site view is the site manager's (fare.request)
        $this->signIn('ceo');
        $this->getJson("/api/v1/team-fares/site?site_id={$this->s1}")->assertForbidden();
        $this->actingAs($this->m, 'api');
        $this->postJson("/api/v1/fare-requests/{$id}/refuse", ['version' => 1, 'reason' => 'x'])->assertForbidden();   // only HR decides
    }

    public function test_approval_is_refused_when_a_later_override_would_take_over_again(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        EmployeeFareOverride::create(['employee_id' => $worker, 'site_id' => $this->s1, 'effective_from' => '2026-12-01', 'amount' => '50']);   // scheduled
        $this->actingAs($this->m, 'api');
        $id = $this->ask($worker, ['amount' => '60'])->assertCreated()->json('data.id');

        $this->actingAs($this->hr, 'api');
        $this->withHeader('Accept-Language', 'ar')->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 1])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from')
            ->assertJsonPath('error.details.0.message', __('errors.fare_later_override', ['date' => '2026-12-01'], 'ar'));
        $this->assertSame('pending', DB::table('fare_change_requests')->where('id', $id)->value('status'));
        $this->assertSame(1, EmployeeFareOverride::where('employee_id', $worker)->count());
        $this->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 1, 'effective_from' => '2026-12-01'])->assertOk();   // on that date: it replaces it
        $this->assertSame('60.000000', (string) EmployeeFareOverride::where(['employee_id' => $worker, 'effective_from' => '2026-12-01'])->value('amount'));
    }

    public function test_approval_into_a_locked_period_is_refused_and_rolls_back_the_decision(): void
    {
        $laborer = $this->worker('day_laborer', $this->s1);
        $this->actingAs($this->m, 'api');
        $id = $this->ask($laborer)->assertCreated()->json('data.id');

        $this->actingAs($this->hr, 'api');
        $this->leaveTypes();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-11-01', 'confirm_missing' => true])->assertCreated();
        $this->postJson("/api/v1/fare-requests/{$id}/approve", ['version' => 1, 'effective_from' => '2026-11-10'])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from');
        $this->assertSame(['pending', 1], [DB::table('fare_change_requests')->where('id', $id)->value('status'), (int) DB::table('fare_change_requests')->where('id', $id)->value('version')]);
        $this->assertSame(0, EmployeeFareOverride::where('employee_id', $laborer)->count());
        $this->assertSame(0, DB::table('notifications')->where('type', 'fare_approved')->count());
    }

    public function test_a_pending_row_the_screen_did_not_know_of_still_refuses_a_second_request(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        DB::table('fare_change_requests')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $worker, 'site_id' => $this->s1, 'requested_amount' => '10',
            'reason' => 'x', 'status' => 'pending', 'version' => 1, 'created_by' => $this->other->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->m, 'api');
        $this->ask($worker)->assertStatus(409)->assertJsonPath('error.code', 'fare_request_pending')->assertJsonPath('error.pending.mine', false);
    }

    public function test_a_replay_tells_hr_when_the_first_send_did_not(): void
    {
        $worker = $this->worker('permanent_worker', $this->s1);
        $this->actingAs($this->m, 'api');
        $key = (string) Str::uuid7();
        $id = $this->ask($worker, ['client_request_id' => $key])->assertCreated()->json('data.id');
        DB::table('notifications')->where('type', 'fare_requested')->delete();   // as if the first send died after its commit
        $this->ask($worker, ['client_request_id' => $key])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('notifications')->where(['type' => 'fare_requested', 'ref' => $id])->count());
        $this->ask($worker, ['client_request_id' => $key])->assertCreated();
        $this->assertSame(1, DB::table('notifications')->where(['type' => 'fare_requested', 'ref' => $id])->count());   // once
    }
}
