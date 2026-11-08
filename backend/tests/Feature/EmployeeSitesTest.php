<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class EmployeeSitesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
    }

    /** @param  array<string, string>  $extra */
    private function move(string $employeeId, string $siteId, string $from, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/employees/{$employeeId}/site-assignments", ['site_id' => $siteId, 'effective_from' => $from, ...$extra]);
    }

    private function assertRefused(TestResponse $r, string $field, string $key): void
    {
        $r->assertStatus(422)->assertJsonPath('error.details.0.field', $field)->assertJsonPath('error.details.0.message', __("errors.{$key}"));
    }

    public function test_hr_moves_a_worker_with_a_dated_audited_row(): void
    {
        [$worker, $site] = [$this->employee('permanent_worker'), $this->workSite()];

        $this->move($worker, $site, '2026-10-01')->assertCreated()->assertJsonPath('data.current_site.id', $site)
            ->assertJsonPath('data.current_site.name_ar', 'Site-1')
            ->assertJsonPath('data.site_assignments.0.site_id', $site)->assertJsonPath('data.site_assignments.0.effective_from', '2026-10-01');
        $this->assertSame(1, DB::table('audit_log')->where(['entity_type' => 'employee_site_assignments', 'event' => 'CREATED'])->count());
    }

    public function test_a_return_date_records_the_return_move(): void
    {
        [$laborer, $s1, $s2] = [$this->employee('day_laborer'), $this->workSite('Site-1'), $this->workSite('Site-2')];
        $this->homeSite($laborer, $s1, '2026-10-01');

        $r = $this->move($laborer, $s2, '2026-10-21', ['returns_on' => '2026-10-22'])->assertCreated()->assertJsonPath('data.current_site.id', $s1);

        $this->assertSame(
            [[$s1, 'Site-1', '2026-10-01'], [$s2, 'Site-2', '2026-10-21'], [$s1, 'Site-1', '2026-10-22']],
            array_map(fn (array $a) => [$a['site_id'], $a['site_name_ar'], $a['effective_from']], $r->json('data.site_assignments')),
        );
    }

    public function test_refusals(): void
    {
        [$worker, $s1, $s2, $s3] = [$this->employee('permanent_worker'), $this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];

        $this->assertRefused($this->move($this->employee('engineer'), $s1, '2026-10-01'), 'effective_from', 'home_site_not_allowed');
        $this->assertRefused($this->move($this->employee('day_laborer', '2026-10-10'), $s1, '2026-10-01'), 'effective_from', 'home_site_not_allowed');
        $this->move($worker, $this->workSite('Site-4', active: false), '2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
        $this->move($worker, $this->leaveTypes()['sick'], '2026-10-01')->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
        $this->assertRefused($this->move($worker, $s1, '2026-10-01', ['returns_on' => '2026-10-02']), 'returns_on', 'no_previous_site');

        $this->homeSite($worker, $s1, '2026-10-01');
        $this->assertRefused($this->move($worker, $s2, '2026-10-01'), 'effective_from', 'duplicate_date');
        $this->homeSite($worker, $s2, '2026-10-25');
        $this->assertRefused($this->move($worker, $s3, '2026-10-20', ['returns_on' => '2026-10-25']), 'returns_on', 'later_move_inside');
        $this->assertRefused($this->move($worker, $s3, '2026-10-20', ['returns_on' => '2026-10-26']), 'returns_on', 'later_move_inside');

        // Each refusal wrote nothing: only the two homeSite rows exist.
        $this->assertSame(2, DB::table('employee_site_assignments')->count());
    }

    public function test_only_assign_site_holders_move_people(): void
    {
        [$worker, $site] = [$this->employee('permanent_worker'), $this->workSite()];

        $this->actingAs($this->managerOf($site), 'api');
        $this->move($worker, $site, '2026-10-01')->assertForbidden();
        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->move($worker, $site, '2026-10-01')->assertForbidden();
        $this->assertSame(0, DB::table('employee_site_assignments')->count());
    }

    public function test_the_list_filters_by_home_site_today(): void
    {
        [$s1, $s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        [$here, $tomorrow] = [$this->employee('permanent_worker'), $this->employee('day_laborer')];
        $this->employee('day_laborer'); // no home site: never listed
        $this->homeSite($here, $s1, '2026-10-01');
        $this->homeSite($tomorrow, $s2, '2026-10-01');
        $this->homeSite($tomorrow, $s1, '2026-10-23');

        $this->getJson("/api/v1/employees?site_id={$s1}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $here);
        $this->getJson("/api/v1/employees?site_id={$s2}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tomorrow);
        $this->getJson('/api/v1/employees?site_id=bogus')->assertStatus(422);
    }
}
