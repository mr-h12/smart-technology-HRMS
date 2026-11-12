<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\SiteWorkbookTemplate;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The site workbook (calculations §14 W1, W4; spec docs/superpowers/specs/2026-11-12-site-workbook-design.md «The file»). */
class SiteWorkbookTemplateTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-12 12:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite('Site-S');
        $this->manager = $this->managerOf($this->site);
    }

    /** W4, spec «The name list»: own people (workers and office staff homed here, engineers of its managers) first, then everyone else; each group by name. */
    public function test_the_sites_own_people_come_first_each_group_by_name(): void
    {
        $other = $this->workSite('Site-T');
        $otherManager = $this->managerOf($other);

        // Created in an order that is not the name order.
        $worker = $this->employee('permanent_worker', '2026-01-01', ['name_ar' => 'Zaki']);
        $this->homeSite($worker, $this->site, '2026-01-01');
        $laborer = $this->employee('day_laborer', '2026-01-01', ['name_ar' => 'Adel']);
        $this->homeSite($laborer, $this->site, '2026-01-01');
        $office = $this->employee('office', '2026-01-01', ['name_ar' => 'Mona']);
        $this->homeSite($office, $this->site, '2026-01-01');
        $engineer = $this->assign($this->employee('engineer', '2026-01-01', ['name_ar' => 'Bassem']), $this->manager);

        $away = $this->employee('day_laborer', '2026-01-01', ['name_ar' => 'Yasser']);
        $this->homeSite($away, $other, '2026-01-01');
        $otherEngineer = $this->assign($this->employee('engineer', '2026-01-01', ['name_ar' => 'Hany']), $otherManager);
        $siteless = $this->employee('permanent_worker', '2026-01-01', ['name_ar' => 'Fady']);

        $names = $this->names();

        $this->assertSame([
            $this->entry($laborer, 'يومية'),
            $this->entry($engineer, 'مهندس'),
            $this->entry($office, 'موظف مكتب'),
            $this->entry($worker, 'عامل مثبت'),
            $this->entry($siteless, 'عامل مثبت'),
            $this->entry($otherEngineer, 'مهندس'),
            $this->entry($away, 'يومية'),
        ], array_column($names, 'entry'));
        $this->assertSame([true, true, true, true, false, false, false], array_column($names, 'own'));
        $this->assertSame($laborer, $names[0]['employee']['id']);
    }

    /** W4, spec «Differences» 14: archived people and other sites' office staff are left out; a site manager's own record is listed. */
    public function test_archived_people_and_other_office_staff_are_left_out_and_a_site_manager_is_listed(): void
    {
        $other = $this->workSite('Site-T');
        $archived = $this->employee('day_laborer', '2026-01-01', ['name_ar' => 'Archived', 'termination_date' => '2026-11-10']);
        $this->homeSite($archived, $this->site, '2026-01-01');
        $elsewhere = $this->employee('office', '2026-01-01', ['name_ar' => 'Office-T']);
        $this->homeSite($elsewhere, $other, '2026-01-01');
        $this->employee('office', '2026-01-01', ['name_ar' => 'Office-none']);
        $own = $this->employee('engineer', '2026-01-01', ['name_ar' => 'Manager-self']);
        DB::table('employees')->where('id', $own)->update(['user_id' => $this->manager->id]);

        $entries = array_column($this->names(), 'entry');

        $this->assertSame([$this->entry($own, 'مهندس')], $entries);
    }

    /** Spec «Differences» 14: the label is the type on the first day of the range the person is employed. */
    public function test_the_type_label_is_the_type_on_the_first_employed_day(): void
    {
        $late = $this->employee('day_laborer', '2026-11-10', ['name_ar' => 'Late']);
        $engineer = $this->employee('engineer', '2026-01-01', ['name_ar' => 'Eng']);
        $worker = $this->employee('permanent_worker', '2026-01-01', ['name_ar' => 'Wrk']);
        $office = $this->employee('office', '2026-01-01', ['name_ar' => 'Off']);
        $this->homeSite($office, $this->site, '2026-01-01');

        $entries = array_column($this->names(), 'entry');

        $this->assertSame([$this->entry($office, 'موظف مكتب'), $this->entry($engineer, 'مهندس'), $this->entry($late, 'يومية'), $this->entry($worker, 'عامل مثبت')], $entries);
    }

    /** @return list<array{entry: string, employee: array<string, mixed>, own: bool}> */
    private function names(): array
    {
        return app(SiteWorkbookTemplate::class)->names($this->site, '2026-11-07', '2026-11-13');
    }

    private function entry(string $employeeId, string $label): string
    {
        $e = DB::table('employees')->where('id', $employeeId)->first(['name_ar', 'code']);

        return "{$e->name_ar} ({$label}) · {$e->code}";
    }

    /** Assigns the engineer to the manager from 10-01; returns the employee id. */
    private function assign(string $employeeId, User $manager): string
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => '2026-10-01']);

        return $employeeId;
    }
}
