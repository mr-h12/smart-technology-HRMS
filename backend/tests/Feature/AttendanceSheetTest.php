<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceSheetTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-1');
    }

    public function test_the_sheet_lists_the_groups_employees_for_the_date_with_their_day_and_version(): void
    {
        $a = $this->employee('engineer', '2026-01-01', ['department' => 'Projects']);
        $b = $this->employee('engineer');
        $this->employee('office');
        $this->putDay($a, '2026-10-18', ['site_id' => $this->site, 'in_time' => '08:00'])->assertOk();

        $this->sheet('engineer', '2026-10-18')->assertOk()
            ->assertJsonPath('data.group', 'engineer')->assertJsonPath('data.date', '2026-10-18')
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.employee.id', $a)->assertJsonPath('data.rows.0.employee.department', 'Projects')
            ->assertJsonPath('data.rows.0.day.state', 'stored')->assertJsonPath('data.rows.0.day.version', 1)
            ->assertJsonPath('data.rows.0.day.site_id', $this->site)->assertJsonPath('data.rows.0.day.in_time', '08:00')
            ->assertJsonPath('data.rows.1.employee.id', $b)->assertJsonPath('data.rows.1.day.state', 'missing')
            ->assertJsonPath('data.rows.1.day.version', null);
    }

    public function test_not_employed_rows_are_left_out(): void
    {
        $this->employee('engineer', '2026-10-19');
        $moved = $this->employee('engineer');
        app(EmployeeService::class)->addTypeChange($moved, ['type' => 'day_laborer', 'effective_from' => '2026-10-16']);

        $this->sheet('engineer', '2026-10-18')->assertJsonCount(0, 'data.rows');                    // hired later; a day laborer since 10-16
        $this->sheet('engineer', '2026-10-10')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.employee.id', $moved);
        $this->sheet('day_laborer', '2026-10-10')->assertJsonCount(0, 'data.rows');
        $this->sheet('day_laborer', '2026-10-18')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.employee.id', $moved);
    }

    public function test_last_site_is_the_latest_stored_work_site_in_the_seven_days_before(): void
    {
        $closed = $this->workSite('Site-2');
        $thursday = $this->employee('engineer');
        $sickBetween = $this->employee('engineer');
        $deactivated = $this->employee('engineer');
        $eightBack = $this->employee('engineer');
        $sevenBack = $this->employee('engineer');
        $sameDay = $this->employee('engineer');
        $office = $this->employee('office');
        $this->putDay($thursday, '2026-10-15', ['site_id' => $this->site]);                          // Friday 10-16 is derived
        $this->putDay($sickBetween, '2026-10-14', ['site_id' => $this->site]);
        $this->putDay($sickBetween, '2026-10-15', ['site_id' => $this->leave['sick']]);
        $this->putDay($deactivated, '2026-10-15', ['site_id' => $closed]);
        $this->putDay($eightBack, '2026-10-09', ['site_id' => $this->site]);
        $this->putDay($sevenBack, '2026-10-10', ['site_id' => $this->site]);
        $this->putDay($sameDay, '2026-10-17', ['site_id' => $this->site]);
        $this->putDay($office, '2026-10-15', ['in_time' => '09:00']);
        Site::whereKey($closed)->update(['active' => false]);

        $last = array_column(array_map(fn (array $r) => ['id' => $r['employee']['id'], 'site' => $r['last_site_id']], $this->sheet('engineer', '2026-10-17')->json('data.rows')), 'site', 'id');
        $this->assertSame([$thursday => $this->site, $sickBetween => $this->site, $deactivated => null, $eightBack => null, $sevenBack => $this->site, $sameDay => null], $last);
        $this->sheet('office', '2026-10-17')->assertJsonPath('data.rows.0.last_site_id', null);
    }

    public function test_period_totals_count_worked_leave_absent_and_missing(): void
    {
        $eng = $this->employee('engineer', '2026-10-12');                                            // employed 10-12 .. ; today 10-20
        $this->putDay($eng, '2026-10-12', ['site_id' => $this->site]);
        $this->putDay($eng, '2026-10-13', ['site_id' => $this->site]);
        $this->putDay($eng, '2026-10-14', ['site_id' => $this->leave['sick']]);
        $this->putDay($eng, '2026-10-15', ['site_id' => $this->leave['absent']]);
        // 10-16 derived Friday; 10-17 .. 10-20 missing; 10-23 a future derived Friday is not counted
        $this->sheet('engineer', '2026-10-20')->assertJsonPath('data.rows.0.totals', ['worked' => 2, 'leave' => 2, 'absent' => 1, 'missing' => 4]);

        $office = $this->employee('office');
        $this->putDay($office, '2026-10-01', ['in_time' => '09:00']);
        $this->sheet('office', '2026-10-01')->assertJsonPath('data.rows.0.totals.worked', 1);
    }

    public function test_the_day_strip_counts_missing_days_per_date(): void
    {
        $a = $this->employee('engineer');
        $this->employee('engineer');
        $this->employee('engineer', '2026-10-10');
        $this->putDay($a, '2026-10-01', ['site_id' => $this->site]);

        $days = array_column($this->sheet('engineer', '2026-10-18')->json('data.days'), 'missing', 'date');
        $this->assertCount(30, $days);
        $this->assertSame('2026-09-26', array_key_first($days));
        $this->assertSame(['2026-10-01' => 1, '2026-10-02' => 0, '2026-10-05' => 2, '2026-10-12' => 3, '2026-10-21' => 0], array_intersect_key($days, array_flip(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-12', '2026-10-21'])));
    }

    public function test_office_rows_carry_the_lateness_hint(): void
    {
        $o = $this->employee('office');
        $later = $this->employee('office', '2026-01-01', ['shift_start' => '10:00', 'shift_end' => '18:00']);
        $this->putDay($o, '2026-10-01', ['in_time' => '09:40']);
        $this->putDay($later, '2026-10-01', ['in_time' => '10:20']);

        $this->sheet('office', '2026-10-01')->assertJsonPath('data.rows.0.hints.late_minutes', 25)->assertJsonPath('data.rows.1.hints.late_minutes', 5);
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->sheet('engineer', '2026-10-21')->assertStatus(422)->assertJsonPath('error.code', 'future_date');
    }

    public function test_a_bad_or_missing_group_or_date_is_refused(): void
    {
        foreach (['group=engineer', 'date=2026-10-18', 'group=nobody&date=2026-10-18', 'group=engineer&date=2026-02-30', 'group=engineer&date=18-10-2026'] as $q) {
            $this->getJson("/api/v1/attendance/sheet?{$q}")->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->getJson('/api/v1/attendance/sheet?date=2026-10-18')->assertJsonPath('error.details.0.field', 'group');
        $this->getJson('/api/v1/attendance/sheet?group=engineer&date=2026-02-30')->assertJsonPath('error.details.0.field', 'date');
    }

    public function test_hints_is_an_empty_object_when_there_is_no_hint(): void
    {
        $this->employee('engineer');
        $this->assertStringContainsString('"hints":{}', (string) $this->sheet('engineer', '2026-10-18')->assertOk()->getContent());
    }

    public function test_ceo_reads_the_sheet_and_a_manager_is_refused(): void
    {
        $this->signIn('ceo');
        $this->sheet('engineer', '2026-10-18')->assertOk();
        $this->signIn('manager');
        $this->sheet('engineer', '2026-10-18')->assertStatus(403);
    }

    public function test_an_own_scope_reader_sees_only_their_employees_on_every_read(): void
    {
        $role = Role::create(['slug' => 'site-lead', 'name' => 'Site lead', 'name_ar' => 'مشرف']);
        $role->permissions()->attach(Permission::create(['resource' => 'attendance', 'action' => 'view', 'scope' => 'own'])->getKey());
        [$lead, $other] = [$this->makeUser('site-lead'), $this->makeUser('manager')];
        [$mine, $theirs] = [$this->employee('engineer', '2026-10-19'), $this->employee('engineer', '2026-10-19')];
        $this->postJson("/api/v1/employees/{$mine}/manager-assignments", ['manager_id' => $lead->id, 'effective_from' => '2026-10-01'])->assertCreated();
        $this->postJson("/api/v1/employees/{$theirs}/manager-assignments", ['manager_id' => $other->id, 'effective_from' => '2026-10-01'])->assertCreated();
        $q = 'group=engineer&date=2026-10-20';

        // HR (`.all`) sees both: 2 missing days each (10-19, 10-20).
        $this->getJson("/api/v1/attendance/sheet?{$q}")->assertJsonCount(2, 'data.rows');
        $this->getJson("/api/v1/attendance/period?{$q}")->assertJsonPath('data.missing', 4);

        $this->actingAs($lead, 'api');
        $sheet = $this->getJson("/api/v1/attendance/sheet?{$q}")->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.employee.id', $mine);
        $this->assertSame(['2026-10-19' => 1, '2026-10-20' => 1], array_filter(array_column($sheet->json('data.days'), 'missing', 'date')));
        $this->getJson("/api/v1/attendance/grid?{$q}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $mine)
            ->assertJsonPath('meta.pagination.total', 1);
        $this->getJson("/api/v1/attendance/missing?{$q}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.employee_id', $mine);
        $this->getJson("/api/v1/attendance/period?{$q}")->assertOk()->assertJsonPath('data.missing', 2);
    }

    public function test_the_sheet_runs_the_same_number_of_queries_for_one_or_three_employees(): void
    {
        $this->putDay($this->employee('engineer'), '2026-10-15', ['site_id' => $this->site]);
        $this->sheet('engineer', '2026-10-18')->assertOk();                                         // warm the settings and role caches
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sheet('engineer', '2026-10-18')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $one = $count();
        foreach ([$this->employee('engineer'), $this->employee('engineer')] as $e) {
            $this->putDay($e, '2026-10-15', ['site_id' => $this->site]);
        }
        $this->assertSame($one, $count());
    }

    public function test_period_accepts_a_date(): void
    {
        $this->getJson('/api/v1/attendance/period?group=engineer&date=2026-09-01')->assertOk()->assertJsonPath('data.start', '2026-08-26');
        $this->getJson('/api/v1/attendance/period?group=engineer&date=2026-10-01&period_start=2026-09-26')->assertStatus(422);
    }

    private function sheet(string $group, string $date): TestResponse
    {
        return $this->getJson("/api/v1/attendance/sheet?group={$group}&date={$date}");
    }
}
