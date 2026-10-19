<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceBulkTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const DATE = '2026-10-18';

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

    public function test_bulk_writes_every_row_in_one_request_and_returns_new_versions(): void
    {
        [$a, $b, $c] = [$this->employee('engineer'), $this->employee('engineer'), $this->employee('engineer')];

        $this->bulk([['employee_id' => $c, 'version' => null, 'site_id' => $this->site], ['employee_id' => $a, 'site_id' => $this->site, 'in_time' => '08:00'], ['employee_id' => $b, 'site_id' => $this->leave['sick']]])
            ->assertOk()->assertJsonPath('data', [['employee_id' => $c, 'version' => 1], ['employee_id' => $a, 'version' => 1], ['employee_id' => $b, 'version' => 1]]);
        $this->assertSame('08:00:00', AttendanceDay::where('employee_id', $a)->value('in_time'));
        $this->assertSame($this->leave['sick'], AttendanceDay::where('employee_id', $b)->value('site_id'));
        $this->assertSame(3, AttendanceDay::where('date', self::DATE)->count());
    }

    public function test_a_permanent_worker_marked_present_gets_shift_defaults_and_full_transport(): void
    {
        $w = $this->employee('permanent_worker');
        $this->bulk([['employee_id' => $w, 'site_id' => $this->site]])->assertOk();
        $this->day($w)->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.transport', 'full');
    }

    public function test_a_stale_row_fails_the_whole_request_and_lists_every_conflict(): void
    {
        [$a, $b, $c] = [$this->employee('engineer'), $this->employee('engineer'), $this->employee('engineer')];
        $this->putDay($b, self::DATE, ['site_id' => $this->site])->assertOk();
        $this->putDay($c, self::DATE, ['site_id' => $this->site])->assertOk();
        $audits = DB::table('audit_log')->count();

        $r = $this->bulk([['employee_id' => $a, 'site_id' => $this->site], ['employee_id' => $b, 'version' => null, 'site_id' => $this->site], ['employee_id' => $c, 'version' => 7, 'site_id' => $this->site]])
            ->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->assertSame(['rows.1', 'rows.2'], array_column($r->json('error.details'), 'field'));
        $this->assertSame(['concurrency_conflict', 'concurrency_conflict'], array_column($r->json('error.details'), 'code'));
        $this->assertNull(AttendanceDay::where('employee_id', $a)->first());
        $this->assertSame($audits, DB::table('audit_log')->count());
    }

    public function test_rule_violations_are_reported_per_row_and_nothing_is_written(): void
    {
        $eng = $this->employee('engineer');
        $office = $this->employee('office');
        $this->bulk([['employee_id' => $office, 'site_id' => $this->site], ['employee_id' => $eng, 'site_id' => $this->site]])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.field', 'rows.0.site_id');
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_a_status_change_keeps_note_and_advance_and_drops_times(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, self::DATE, ['site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00', 'note' => 'n', 'advance_amount' => '500'])->assertOk();

        $this->bulk([['employee_id' => $eng, 'version' => 1, 'site_id' => $this->leave['absent']]])->assertOk()->assertJsonPath('data.0.version', 2);
        $this->day($eng)->assertJsonPath('data.leave_code', 'absent')->assertJsonPath('data.note', 'n')
            ->assertJsonPath('data.advance_amount', '500.000000')->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);
    }

    public function test_an_office_time_edit_keeps_the_out_time(): void
    {
        $o = $this->employee('office');
        $this->putDay($o, self::DATE, ['in_time' => '09:00', 'out_time' => '17:00'])->assertOk();

        $this->bulk([['employee_id' => $o, 'version' => 1, 'site_id' => null, 'in_time' => '09:20']])->assertOk();
        $this->day($o)->assertJsonPath('data.in_time', '09:20')->assertJsonPath('data.out_time', '17:00');
    }

    public function test_a_sick_permanent_worker_marked_present_gets_full_transport_again(): void
    {
        $w = $this->employee('permanent_worker');
        $this->putDay($w, self::DATE, ['site_id' => $this->leave['sick'], 'note' => 'n'])->assertJsonPath('data.transport', 'none');

        $this->bulk([['employee_id' => $w, 'version' => 1, 'site_id' => $this->site]])->assertOk();
        $this->day($w)->assertJsonPath('data.transport', 'full')->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.note', 'n');
    }

    public function test_clear_rows_restore_the_missing_or_derived_day(): void
    {
        $eng = $this->employee('engineer');
        $this->bulk([['employee_id' => $eng, 'site_id' => $this->site]])->assertJsonPath('data.0.version', 1);
        $this->day($eng)->assertJsonPath('data.state', 'stored');
        $this->bulk([['employee_id' => $eng, 'version' => 1, 'clear' => true]])->assertOk()->assertJsonPath('data.0.version', null);
        $this->day($eng)->assertJsonPath('data.state', 'missing');

        $friday = '2026-10-16';
        $this->bulk([['employee_id' => $eng, 'site_id' => $this->site]], $friday)->assertOk();
        $this->bulk([['employee_id' => $eng, 'version' => 1, 'clear' => true]], $friday)->assertOk();
        $this->day($eng, $friday)->assertJsonPath('data.state', 'derived')->assertJsonPath('data.leave_code', 'friday');
        $this->bulk([['employee_id' => $eng, 'clear' => true]])->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.0.version');
        $this->bulk([['employee_id' => $eng]])->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.0.site_id');   // site_id is sent unless clearing
    }

    public function test_an_unchanged_row_writes_nothing(): void
    {
        $eng = $this->employee('engineer');
        $this->putDay($eng, self::DATE, ['site_id' => $this->site, 'in_time' => '08:00', 'advance_amount' => '500'])->assertOk();
        $audits = DB::table('audit_log')->count();

        $this->bulk([['employee_id' => $eng, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:00', 'advance_amount' => '500']])->assertOk()->assertJsonPath('data.0.version', 1);
        $this->bulk([['employee_id' => $eng, 'version' => 1, 'site_id' => $this->site]])->assertOk()->assertJsonPath('data.0.version', 1);   // the kept values are the stored ones
        $this->putDay($eng, self::DATE, ['version' => 1, 'site_id' => $this->site, 'in_time' => '08:00', 'advance_amount' => '500.000000'])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame($audits, DB::table('audit_log')->count());
    }

    public function test_a_locked_period_refuses_the_whole_request(): void
    {
        $eng = $this->employee('engineer');
        $office = $this->employee('office');
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();

        $this->bulk([['employee_id' => $office, 'site_id' => null, 'in_time' => '09:00'], ['employee_id' => $eng, 'site_id' => $this->site]])
            ->assertStatus(423)->assertJsonPath('error.code', 'period_locked')->assertJsonPath('error.details.0.field', 'rows.1');
        $this->assertSame(0, AttendanceDay::where('date', self::DATE)->count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->bulk([['employee_id' => $this->employee('engineer'), 'site_id' => $this->site]], '2026-10-21')
            ->assertStatus(422)->assertJsonPath('error.code', 'future_date')->assertJsonPath('error.details.0.field', 'rows.0');
    }

    public function test_more_than_500_rows_is_refused(): void
    {
        $rows = array_map(fn () => ['employee_id' => (string) Str::uuid7(), 'site_id' => null], range(1, 501));
        $this->bulk($rows)->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows');
    }

    public function test_string_keyed_rows_are_refused_and_nothing_is_written(): void
    {
        [$a, $b] = [$this->employee('engineer'), $this->employee('engineer')];
        $audits = DB::table('audit_log')->count();
        foreach ([['x' => ['employee_id' => $a, 'site_id' => $this->site]], ['x' => ['employee_id' => $a, 'site_id' => $this->site], 'y' => ['employee_id' => $b, 'site_id' => $this->site]]] as $rows) {
            $this->postJson('/api/v1/attendance/days/bulk', ['date' => self::DATE, 'rows' => $rows])
                ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.field', 'rows');
        }
        $this->assertSame(0, AttendanceDay::count());
        $this->assertSame($audits, DB::table('audit_log')->count());
    }

    public function test_duplicate_employee_rows_are_refused(): void
    {
        $eng = $this->employee('engineer');
        $r = $this->bulk([['employee_id' => $eng, 'site_id' => $this->site], ['employee_id' => $eng, 'site_id' => $this->leave['sick']]])->assertStatus(422);
        $this->assertContains('rows.1.employee_id', array_column($r->json('error.details'), 'field'));
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_every_written_day_has_its_own_audit_entry(): void
    {
        $ids = [$this->employee('engineer'), $this->employee('engineer'), $this->employee('engineer')];
        $this->bulk(array_map(fn (string $id) => ['employee_id' => $id, 'site_id' => $this->site], $ids))->assertOk();
        $audits = DB::table('audit_log')->where('entity_type', 'attendance_days')->get();
        $this->assertSame(['CREATED', 'CREATED', 'CREATED'], $audits->pluck('event')->all());
        $this->assertEqualsCanonicalizing(AttendanceDay::pluck('id')->all(), $audits->pluck('entity_id')->all());
    }

    public function test_ceo_and_manager_are_refused(): void
    {
        $eng = $this->employee('engineer');
        foreach (['ceo', 'manager'] as $role) {
            $this->signIn($role);
            $this->bulk([['employee_id' => $eng, 'site_id' => $this->site]])->assertStatus(403);
        }
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_an_own_scope_user_cannot_reach_another_managers_employee(): void
    {
        $role = Role::create(['slug' => 'site-lead', 'name' => 'Site lead', 'name_ar' => 'مشرف']);
        $role->permissions()->attach(Permission::create(['resource' => 'attendance', 'action' => 'edit', 'scope' => 'own'])->getKey());
        [$lead, $other] = [$this->makeUser('site-lead'), $this->makeUser('manager')];
        [$mine, $theirs] = [$this->employee('engineer'), $this->employee('engineer')];
        $this->postJson("/api/v1/employees/{$mine}/manager-assignments", ['manager_id' => $lead->id, 'effective_from' => '2026-10-01'])->assertCreated();
        $this->postJson("/api/v1/employees/{$theirs}/manager-assignments", ['manager_id' => $other->id, 'effective_from' => '2026-10-01'])->assertCreated();

        $this->actingAs($lead, 'api');
        $this->bulk([['employee_id' => $mine, 'site_id' => $this->site], ['employee_id' => $theirs, 'site_id' => $this->site]])->assertNotFound();
        $this->bulk([['employee_id' => (string) Str::uuid7(), 'site_id' => $this->site]])->assertNotFound();
        $this->assertSame(0, AttendanceDay::count());
        $this->bulk([['employee_id' => $mine, 'site_id' => $this->site]])->assertOk();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulk(array $rows, string $date = self::DATE): TestResponse
    {
        return $this->postJson('/api/v1/attendance/days/bulk', ['date' => $date, 'rows' => $rows]);
    }

    private function day(string $employeeId, string $date = self::DATE): TestResponse
    {
        return $this->getJson("/api/v1/attendance/days/{$employeeId}/{$date}");
    }
}
