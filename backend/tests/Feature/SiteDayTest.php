<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The manager's site list: the site's workers on the date, then the manager's engineers, with no pay figures (design Part 2). */
class SiteDayTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private User $m;

    private string $e1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        [$this->s1, $this->s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->homeSite($this->employee('permanent_worker'), $this->s1, '2026-10-01');      // Worker-1
        $this->homeSite($this->employee('permanent_worker'), $this->s2, '2026-10-01');      // Worker-2, on the other site
        $this->homeSite($this->employee('day_laborer'), $this->s1, '2026-10-01');           // Laborer-1
        $this->e1 = $this->employee('engineer');
        app(EmployeeService::class)->assignManager($this->e1, ['manager_id' => $this->m->id, 'effective_from' => '2026-10-01']);
        $this->employee('engineer');                                                          // Engineer-2, nobody's
    }

    public function test_the_list_holds_the_sites_workers_then_the_managers_engineers(): void
    {
        $this->actingAs($this->m, 'api');
        $r = $this->siteDay($this->s1)->assertOk()
            ->assertJsonPath('data.site.id', $this->s1)->assertJsonPath('data.site.name_ar', 'Site-1')
            ->assertJsonPath('data.date', '2026-10-22')->assertJsonPath('data.today', '2026-10-22');

        $this->assertSame(['Worker-1', 'Laborer-1', 'Engineer-1'], $r->json('data.rows.*.employee.name_ar'));
        $this->assertSame(['workers', 'workers', 'engineers'], $r->json('data.rows.*.section'));
        $this->assertSame(['permanent_worker', 'day_laborer', 'engineer'], $r->json('data.rows.*.employee.type'));
        foreach ($r->json('data.rows') as $row) {
            $this->assertSame(['state', 'version', 'site_id', 'leave_code', 'in_time', 'out_time', 'note'], array_keys($row['day']));
            $this->assertSame('missing', $row['day']['state']);
        }
        $this->assertStringNotContainsString('transport', $r->getContent());
        $this->assertStringNotContainsString('advance_amount', $r->getContent());
        $this->assertDoesNotMatchRegularExpression('/"(pays|salary|daily_wage)":/', $r->getContent());   // roster rows carry `pays`; no wage key at any depth
    }

    public function test_engineers_appear_on_every_site_of_their_manager_and_show_where_they_are(): void
    {
        $this->putDay($this->e1, '2026-10-22', ['site_id' => $this->s2])->assertOk();

        $this->actingAs($this->m, 'api');
        $this->assertSame(['Worker-2', 'Engineer-1'], $this->siteDay($this->s2)->json('data.rows.*.employee.name_ar'));
        $row = $this->siteDay($this->s1)->assertOk()->json('data.rows.2');
        $this->assertSame([$this->e1, 'stored', $this->s2], [$row['employee']['id'], $row['day']['state'], $row['day']['site_id']]);
    }

    public function test_another_managers_site_is_404_and_a_future_date_is_refused(): void
    {
        $s3 = $this->workSite('Site-3');
        $this->managerOf($s3);

        $this->actingAs($this->m, 'api');
        $this->siteDay($s3)->assertNotFound();
        $this->siteDay((string) Str::uuid7())->assertNotFound();
        $this->siteDay($this->s1, '2026-10-23')->assertStatus(422)->assertJsonPath('error.code', 'future_date');
        $this->siteDay($this->s1, '2026-10-21')->assertOk()->assertJsonPath('data.date', '2026-10-21');
        $this->getJson('/api/v1/attendance/site-day')->assertStatus(422);
    }

    public function test_hr_and_ceo_are_refused(): void
    {
        $this->siteDay($this->s1)->assertForbidden();
        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->siteDay($this->s1)->assertForbidden();
    }

    private function siteDay(string $siteId, ?string $date = null): TestResponse
    {
        return $this->getJson('/api/v1/attendance/site-day?'.http_build_query(array_filter(['site_id' => $siteId, 'date' => $date])));
    }
}
