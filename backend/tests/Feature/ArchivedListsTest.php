<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceTemplate;
use App\Modules\Employees\Domain\EmployeeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** An archived employee is on no list by default (owner, 2026-11-11); the lock and the import still include them. */
class ArchivedListsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite();
    }

    /** @return list<string> */
    private function ids(string $url, string $path): array
    {
        return $this->getJson($url)->assertOk()->json($path);
    }

    /** @return list<string> the employee ids of every list an archived employee must leave */
    private function onEveryList(): array
    {
        return [
            ...$this->ids('/api/v1/attendance/grid?group=engineer&per_page=50', 'data.*.employee.id'),
            ...$this->ids('/api/v1/attendance/grid?group=all&per_page=50', 'data.*.employee.id'),
            ...$this->ids('/api/v1/attendance/sheet?group=engineer&date=2026-10-12', 'data.rows.*.employee.id'),
            ...$this->ids('/api/v1/attendance/sheet?group=all&date=2026-10-12', 'data.rows.*.employee.id'),
            ...array_column(app(AttendanceTemplate::class)->grid(EmployeeType::Engineer, '2026-09-26', null)['rows'], 'employee_id'),
            ...$this->ids('/api/v1/leave/balances', 'data.*.id'),
        ];
    }

    private function leftEngineer(): string
    {
        $left = $this->employee('engineer', '2025-01-01', ['termination_date' => '2026-10-19']);
        $this->putDay($left, '2026-10-12', ['site_id' => $this->site])->assertOk();

        return $left;
    }

    public function test_an_archived_employee_is_on_no_attendance_or_leave_list(): void
    {
        $left = $this->leftEngineer();
        $stays = $this->employee('engineer');

        $listed = $this->onEveryList();
        $this->assertNotContains($left, $listed);
        $this->assertContains($stays, $listed);
        $this->assertNotContains($left, $this->ids('/api/v1/leave/balances?year=2025', 'data.*.id'));
        $strip = collect($this->getJson('/api/v1/attendance/sheet?group=engineer&date=2026-10-12')->json('data.days'))->keyBy('date');
        $this->assertSame(1, $strip['2026-10-13']['missing']);                                     // $stays only

        $w = $this->employee('permanent_worker', '2026-01-01', ['termination_date' => '2026-10-19']);
        $this->homeSite($w, $this->site, '2026-01-01');
        $m = $this->managerOf($this->site);
        $this->cairo('2026-10-20 09:00');                                                           // before noon: yesterday is still open
        $this->actingAs($m, 'api');
        $this->assertNotContains($w, $this->ids("/api/v1/attendance/site-day?site_id={$this->site}&date=2026-10-19", 'data.rows.*.employee.id'));
    }

    public function test_reactivating_brings_the_employee_back_to_every_list(): void
    {
        $left = $this->leftEngineer();
        $this->assertNotContains($left, $this->onEveryList());

        $this->patchJson("/api/v1/employees/{$left}", ['termination_date' => null, 'termination_reason' => null])->assertOk();

        $this->assertCount(6, array_keys($this->onEveryList(), $left, true));                      // once on each of the six lists
    }
}
