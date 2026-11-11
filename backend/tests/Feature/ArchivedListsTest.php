<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceGrid;
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

    /** $left (terminated 10-19) and $stays, both engineers with no stored day in the period of 2026-09-26: 21 and 22 missing days. */
    private function twoEngineers(): array
    {
        return [$this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-19']), $this->employee('engineer')];
    }

    public function test_hr_brings_archived_employees_back_to_the_grid_and_its_header_count(): void
    {
        [$left, $stays] = $this->twoEngineers();

        $this->getJson('/api/v1/attendance/period?group=engineer')->assertOk()->assertJsonPath('data.missing', 22);
        $this->getJson('/api/v1/attendance/period?group=engineer&with_archived=1')->assertOk()->assertJsonPath('data.missing', 22 + 21);
        $rows = $this->getJson('/api/v1/attendance/grid?group=engineer&with_archived=1')->assertOk()->json('data');
        $this->assertSame([[$left, true], [$stays, false]], array_map(fn (array $r) => [$r['employee']['id'], $r['employee']['archived']], $rows));
        $this->assertSame([$stays], $this->ids('/api/v1/attendance/grid?group=engineer', 'data.*.employee.id'));
        $this->getJson('/api/v1/attendance/grid?group=engineer&with_archived=maybe')->assertStatus(422);
    }

    public function test_with_archived_is_ignored_without_attendance_edit_at_all_scope(): void
    {
        [, $stays] = $this->twoEngineers();
        $this->signIn('ceo');                                                                       // attendance.view.all only

        $this->assertSame([$stays], $this->ids('/api/v1/attendance/grid?group=engineer&with_archived=1', 'data.*.employee.id'));
        $this->getJson('/api/v1/attendance/period?group=engineer&with_archived=1')->assertOk()->assertJsonPath('data.missing', 22);
    }

    public function test_the_site_filtered_grid_keeps_an_archived_office_employee_with_the_switch(): void
    {
        $o = $this->employee('office', '2026-01-01', ['termination_date' => '2026-10-19']);
        $this->homeSite($o, $this->site, '2026-01-01');

        $this->assertSame([$o], $this->ids("/api/v1/attendance/grid?group=office&site_id={$this->site}&with_archived=1", 'data.*.employee.id'));
        $this->assertSame([], $this->ids("/api/v1/attendance/grid?group=office&site_id={$this->site}", 'data.*.employee.id'));
    }

    public function test_the_missing_list_still_lists_archived_employees_flagged(): void
    {
        [$left, $stays] = $this->twoEngineers();

        $rows = $this->getJson('/api/v1/attendance/missing?group=engineer&per_page=100')->assertOk()->json('data');
        $flags = collect($rows)->groupBy('employee_id')->map(fn ($r) => [count($r), $r->pluck('archived')->unique()->all()])->all();
        $this->assertSame([$left => [21, [true]], $stays => [22, [false]]], $flags);
    }

    public function test_the_evening_alert_leaves_archived_employees_out(): void
    {
        [$left] = $this->twoEngineers();
        $this->putDay($left, '2026-10-12', ['site_id' => $this->site, 'in_time' => '08:00'])->assertOk();   // an open day

        $row = collect(app(AttendanceGrid::class)->missingCounts('2026-10-20'))->firstWhere(fn (array $r) => $r['group'] === 'engineer' && $r['period_start'] === '2026-09-26');
        $this->assertSame([22, 0], [$row['missing'], $row['open']]);
    }
}
