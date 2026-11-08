<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** HR's site filter (owner, 2026-11-08): office staff get a dated site that only lists them there; every sheet row says where its employee is on the date. */
class HrSiteFilterTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    private string $s1;

    private string $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        [$this->s1, $this->s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
    }

    private function sheet(string $group, string $date = '2026-10-20'): TestResponse
    {
        return $this->getJson("/api/v1/attendance/sheet?group={$group}&date={$date}")->assertOk();
    }

    /** @return array<string, array<string, mixed>> employee id → sheet row */
    private function rows(string $group, string $date = '2026-10-20'): array
    {
        $rows = [];
        foreach ($this->sheet($group, $date)->json('data.rows') as $r) {
            $rows[$r['employee']['id']] = $r;
        }

        return $rows;
    }

    private function officeAt(string $site): string
    {
        $office = $this->employee('office');
        $this->homeSite($office, $site, '2026-10-01');

        return $office;
    }

    public function test_hr_gives_office_staff_a_dated_audited_site(): void
    {
        $office = $this->employee('office');

        $this->postJson("/api/v1/employees/{$office}/site-assignments", ['site_id' => $this->s1, 'effective_from' => '2026-10-01'])->assertCreated()
            ->assertJsonPath('data.current_site.id', $this->s1)->assertJsonPath('data.site_assignments.0.effective_from', '2026-10-01');
        $this->assertSame(1, DB::table('audit_log')->where(['entity_type' => 'employee_site_assignments', 'event' => 'CREATED'])->count());
    }

    public function test_an_engineer_is_refused_a_site_with_wording_that_names_who_may_have_one(): void
    {
        $engineer = $this->employee('engineer');

        $this->postJson("/api/v1/employees/{$engineer}/site-assignments", ['site_id' => $this->s1, 'effective_from' => '2026-10-01'])->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'effective_from')->assertJsonPath('error.details.0.message', __('errors.home_site_not_allowed'));
        $this->assertStringContainsString('office', __('errors.home_site_not_allowed', locale: 'en'));
        $this->assertSame(0, DB::table('employee_site_assignments')->count());
    }

    public function test_a_stored_work_site_comes_first(): void
    {
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-10-01');
        $this->putDay($worker, '2026-10-20', ['site_id' => $this->s2])->assertOk();

        $this->assertSame($this->s2, $this->rows('permanent_worker')[$worker]['site_id']);
    }

    public function test_without_a_work_day_a_worker_or_office_employee_is_at_their_site_on_the_date(): void
    {
        [$worker, $laborer] = [$this->employee('permanent_worker'), $this->employee('day_laborer')];
        $this->homeSite($worker, $this->s1, '2026-10-01');
        $this->homeSite($worker, $this->s2, '2026-10-21');                                          // not yet in force
        $this->homeSite($laborer, $this->s1, '2026-10-01');
        DayCrewChange::create(['employee_id' => $laborer, 'date' => '2026-10-20', 'site_id' => $this->s2]);
        $present = $this->officeAt($this->s1);
        $sick = $this->officeAt($this->s2);
        $this->putDay($present, '2026-10-20', ['in_time' => '09:00'])->assertOk();                  // an office present day stores no site
        $this->putDay($sick, '2026-10-20', ['site_id' => $this->leave['sick']])->assertOk();        // a leave type is not a work site

        $this->assertSame($this->s1, $this->rows('permanent_worker')[$worker]['site_id']);
        $this->assertSame($this->s2, $this->rows('day_laborer')[$laborer]['site_id']);              // that day's crew change wins
        $office = $this->rows('office');
        $this->assertSame($this->s1, $office[$present]['site_id']);
        $this->assertSame($this->s2, $office[$sick]['site_id']);
    }

    public function test_without_a_work_day_an_engineer_is_at_their_last_site_never_a_home_site(): void
    {
        $engineer = $this->employee('permanent_worker');
        $this->homeSite($engineer, $this->s1, '2026-01-01');
        app(EmployeeService::class)->addTypeChange($engineer, ['type' => 'engineer', 'effective_from' => '2026-09-26']);

        $this->assertNull($this->rows('engineer')[$engineer]['site_id']);                           // the home site from when they were a worker places nobody
        $this->putDay($engineer, '2026-10-18', ['site_id' => $this->s2])->assertOk();
        $this->assertSame($this->s2, $this->rows('engineer')[$engineer]['site_id']);
    }

    public function test_a_worker_with_no_site_on_the_date_is_at_their_last_site(): void
    {
        [$worker, $laborer] = [$this->employee('permanent_worker'), $this->employee('day_laborer')];
        $this->putDay($worker, '2026-10-18', ['site_id' => $this->s1])->assertOk();
        $this->putDay($laborer, '2026-10-18', ['site_id' => $this->s2])->assertOk();

        $this->assertSame($this->s1, $this->rows('permanent_worker')[$worker]['site_id']);
        $this->assertSame($this->s2, $this->rows('day_laborer')[$laborer]['site_id']);
    }

    public function test_the_site_on_the_date_comes_before_the_last_site(): void
    {
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-10-01');
        $this->putDay($worker, '2026-10-18', ['site_id' => $this->s2])->assertOk();

        $this->assertSame($this->s1, $this->rows('permanent_worker')[$worker]['site_id']);
    }

    public function test_a_worker_with_neither_a_site_on_the_date_nor_a_last_site_has_no_site(): void
    {
        $worker = $this->employee('permanent_worker');

        $this->assertNull($this->rows('permanent_worker')[$worker]['site_id']);
    }

    public function test_a_laborer_taken_off_every_crew_has_no_site_even_with_a_last_site(): void
    {
        $laborer = $this->employee('day_laborer');
        $this->homeSite($laborer, $this->s1, '2026-10-01');
        $this->putDay($laborer, '2026-10-18', ['site_id' => $this->s2])->assertOk();
        DayCrewChange::create(['employee_id' => $laborer, 'date' => '2026-10-20', 'site_id' => null]);

        $row = $this->rows('day_laborer')[$laborer];
        $this->assertNull($row['site_id']);
        $this->assertSame($this->s2, $row['last_site_id']);
    }

    public function test_anyone_else_has_no_site(): void
    {
        [$engineer, $worker, $office] = [$this->employee('engineer'), $this->employee('day_laborer'), $this->employee('office')];

        $this->assertNull($this->rows('engineer')[$engineer]['site_id']);
        $this->assertNull($this->rows('day_laborer')[$worker]['site_id']);
        $this->assertNull($this->rows('office')[$office]['site_id']);
    }

    public function test_a_single_group_sheet_keeps_its_shape_and_adds_the_type(): void
    {
        $engineer = $this->employee('engineer');
        $this->employee('office');

        $r = $this->sheet('engineer')->assertJsonPath('data.group', 'engineer')->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.employee.id', $engineer)->assertJsonPath('data.rows.0.employee.type', 'engineer');
        $this->assertSame(1, array_column($r->json('data.days'), 'missing', 'date')['2026-10-20']);
    }

    public function test_group_all_merges_every_type_by_code_each_in_its_own_period(): void
    {
        [$engineer, $worker, $laborer, $office] = [$this->employee('engineer'), $this->employee('permanent_worker'), $this->employee('day_laborer'), $this->officeAt($this->s1)];
        $this->employee('day_laborer', '2026-10-21');                                               // hired after the date: left out
        $this->putDay($engineer, '2026-10-10', ['site_id' => $this->s1])->assertOk();
        $this->putDay($laborer, '2026-10-10', ['site_id' => $this->s1])->assertOk();                // the laborers' 1–15, not the period holding 10-20
        $this->putDay($office, '2026-10-20', ['in_time' => '09:40'])->assertOk();

        $r = $this->sheet('all')->assertJsonPath('data.group', 'all')->assertJsonPath('data.date', '2026-10-20');
        $rows = $r->json('data.rows');

        $this->assertSame([$engineer, $worker, $laborer, $office], array_map(fn (array $x) => $x['employee']['id'], $rows));
        $this->assertSame(['engineer', 'permanent_worker', 'day_laborer', 'office'], array_map(fn (array $x) => $x['employee']['type'], $rows));
        $this->assertSame(1, $rows[0]['totals']['worked']);
        $this->assertSame(0, $rows[2]['totals']['worked']);
        $this->assertSame(25, $rows[3]['hints']['late_minutes']);
        $this->assertSame($this->s1, $rows[3]['site_id']);
    }

    public function test_group_all_days_are_the_engineers_window_with_no_counts(): void
    {
        $this->employee('engineer');
        $days = $this->sheet('all')->json('data.days');

        $this->assertSame(['2026-09-26', '2026-10-25'], [$days[0]['date'], $days[array_key_last($days)]['date']]);
        $this->assertSame([null], array_values(array_unique(array_column($days, 'missing'))));
        $off = array_column($days, 'off', 'date');
        $this->assertTrue($off['2026-10-02']);                                                      // Friday
        $this->assertFalse($off['2026-10-03']);                                                     // Saturday: the office's weekly off, not the engineers'
    }

    public function test_group_all_stays_inside_an_own_scope_readers_reach(): void
    {
        $lead = $this->userWith('own', null);
        DB::table('manager_sites')->insert(['manager_id' => $lead->id, 'site_id' => $this->s1]);
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-10-01');
        $this->officeAt($this->s1);
        $this->employee('engineer');

        $this->actingAs($lead, 'api');
        $r = $this->sheet('all')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.employee.id', $worker);
        $this->assertNotContains('office', array_map(fn (array $x) => $x['employee']['type'], $r->json('data.rows')));
    }

    /** @return list<string> */
    private function gridIds(string $query): array
    {
        return array_column($this->getJson("/api/v1/attendance/grid?{$query}&per_page=50")->assertOk()->json('data.*.employee'), 'id');
    }

    public function test_the_grid_with_a_site_adds_office_staff_at_that_site_at_the_periods_end(): void
    {
        $worked = $this->employee('day_laborer');
        $this->putDay($worked, '2026-10-05', ['site_id' => $this->s1])->assertOk();
        $here = $this->officeAt($this->s1);
        $left = $this->officeAt($this->s1);
        $this->homeSite($left, $this->s2, '2026-10-22');                                            // by the period's end they are at Site-2
        $this->officeAt($this->s2);
        $this->employee('office');

        $this->assertSame([$worked, $here], $this->gridIds("group=all&period_start=2026-09-26&site_id={$this->s1}"));
        $this->assertSame([$here], $this->gridIds("group=office&period_start=2026-10-01&site_id={$this->s1}"));
        $this->assertSame([], $this->gridIds("group=engineer&period_start=2026-09-26&site_id={$this->s1}"));
    }

    public function test_grid_all_reads_office_days_in_the_calendar_month_they_belong_to(): void
    {
        $office = $this->employee('office');
        $this->putDay($office, '2026-09-30', ['in_time' => '09:00'])->assertOk();
        $this->putDay($office, '2026-10-01', ['in_time' => '09:00'])->assertOk();

        $days = $this->getJson('/api/v1/attendance/grid?group=all&period_start=2026-09-26')->assertOk()->json('data.0.days');
        $state = array_column($days, 'state', 'date');

        $this->assertCount(30, $days);
        $this->assertSame(['stored', 'stored'], [$state['2026-09-30'], $state['2026-10-01']]);
        $this->assertSame(['derived', 'derived'], [$state['2026-09-26'], $state['2026-10-03']]);    // Saturdays: the office's weekly off in both months
        $this->assertSame('missing', $state['2026-09-27']);                                         // a Sunday in September
    }

    /** @return array{string, User} an office employee homed on a site this manager runs, and that manager */
    private function officeOnAManagersSite(): array
    {
        return [$this->officeAt($this->s1), $this->managerOf($this->s1)];
    }

    private function asManager(User $manager): void
    {
        $this->actingAs($manager, 'api');
    }

    public function test_a_site_gives_a_manager_no_reach_over_office_staff(): void
    {
        [$office, $manager] = $this->officeOnAManagersSite();

        $this->assertFalse(app(EmployeeService::class)->isManagedBy($office, $manager->id));
        $this->asManager($manager);
        $this->assertSame([], array_column($this->getJson('/api/v1/employees?per_page=100')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/employees/{$office}")->assertNotFound();
    }

    public function test_the_employee_list_by_site_stays_workers_only(): void
    {
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-10-01');
        $this->officeAt($this->s1);

        $this->getJson("/api/v1/employees?site_id={$this->s1}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $worker);
    }

    public function test_a_site_does_not_put_office_staff_on_the_site_day(): void
    {
        [$office, $manager] = $this->officeOnAManagersSite();
        $worker = $this->employee('permanent_worker');
        $this->homeSite($worker, $this->s1, '2026-10-01');

        $this->asManager($manager);
        $ids = $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}&date=2026-10-20")->assertOk()->json('data.rows.*.employee.id');
        $this->assertSame([$worker], $ids);
        $this->assertNotContains($office, $ids);
    }

    public function test_the_crew_search_never_finds_office_staff(): void
    {
        [$office, $manager] = $this->officeOnAManagersSite();
        $code = DB::table('employees')->where('id', $office)->value('code');

        $this->asManager($manager);
        $this->getJson('/api/v1/attendance/site-day/laborers?'.http_build_query(['site_id' => $this->s1, 'search' => (string) $code]))->assertOk()->assertJsonPath('data.match', null);
    }

    public function test_team_fares_leave_office_staff_out(): void
    {
        [, $manager] = $this->officeOnAManagersSite();

        $this->asManager($manager);
        $this->getJson('/api/v1/team-fares')->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_a_site_never_lands_on_an_office_day_so_site_costing_cannot_count_it(): void
    {
        $office = $this->officeAt($this->s1);
        $this->putDay($office, '2026-10-20', ['in_time' => '09:00'])->assertOk();

        $this->assertNull(DB::table('attendance_days')->where('employee_id', $office)->value('site_id'));
        $this->assertSame($this->s1, $this->rows('office')[$office]['site_id']);
    }
}
