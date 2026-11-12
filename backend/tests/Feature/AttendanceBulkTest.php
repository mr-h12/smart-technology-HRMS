<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->day($w)->assertJsonPath('data.scheduled_in', '08:00')->assertJsonPath('data.scheduled_out', '17:00')->assertJsonPath('data.transport', 'full')
            ->assertJsonPath('data.in_time', '08:00')->assertJsonPath('data.out_time', '17:00');
    }

    /** @return array<string, array{string}> */
    public static function fieldTypes(): array
    {
        return ['engineer' => ['engineer'], 'permanent worker' => ['permanent_worker'], 'day laborer' => ['day_laborer']];
    }

    #[DataProvider('fieldTypes')]
    public function test_a_field_employee_marked_present_gets_the_field_shift_times(string $type): void
    {
        $e = $this->employee($type);
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site]])->assertOk();
        $this->day($e)->assertJsonPath('data.in_time', '08:00')->assertJsonPath('data.out_time', '17:00');
    }

    public function test_office_present_and_a_leave_day_get_no_times(): void
    {
        [$office, $eng] = [$this->employee('office'), $this->employee('engineer')];
        $this->bulk([['employee_id' => $office, 'site_id' => null], ['employee_id' => $eng, 'site_id' => $this->leave['sick']]])->assertOk();
        $this->day($office)->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);
        $this->day($eng)->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);
    }

    public function test_the_employees_own_shift_wins_over_the_field_shift(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['shift_start' => '07:00', 'shift_end' => '15:00']);
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site]])->assertOk();
        $this->day($e)->assertJsonPath('data.in_time', '07:00')->assertJsonPath('data.out_time', '15:00');
    }

    public function test_the_default_departure_follows_the_setting(): void
    {
        $this->putJson('/api/v1/settings', ['worker_shift_end' => '18:30'])->assertOk();
        $e = $this->employee('day_laborer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site]])->assertOk();
        $this->day($e)->assertJsonPath('data.out_time', '18:30');
    }

    public function test_a_sent_time_wins_and_only_the_unsent_one_is_filled(): void
    {
        $e = $this->employee('engineer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'out_time' => '19:00']])->assertOk();
        $this->day($e)->assertJsonPath('data.in_time', '08:00')->assertJsonPath('data.out_time', '19:00');
    }

    public function test_a_kept_time_wins_over_the_default(): void
    {
        $e = $this->employee('engineer');
        $this->putDay($e, self::DATE, ['site_id' => $this->site, 'in_time' => '09:15', 'out_time' => '18:00'])->assertOk();
        $other = $this->workSite('Site-2');
        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $other]])->assertOk();
        $this->day($e)->assertJsonPath('data.site_id', $other)->assertJsonPath('data.in_time', '09:15')->assertJsonPath('data.out_time', '18:00');
    }

    public function test_leave_to_work_fills_the_default_times(): void
    {
        $e = $this->employee('day_laborer');
        $this->putDay($e, self::DATE, ['site_id' => $this->leave['sick'], 'note' => 'n'])->assertOk();
        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site]])->assertOk();
        $this->day($e)->assertJsonPath('data.in_time', '08:00')->assertJsonPath('data.out_time', '17:00')->assertJsonPath('data.note', 'n');
    }

    public function test_a_sent_null_clears_the_time_and_is_not_filled(): void
    {
        $e = $this->employee('day_laborer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => null]])->assertOk();
        $this->day($e)->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', '17:00');
    }

    /** @return array<string, array{string}> */
    public static function minuteTypes(): array
    {
        return ['engineer' => ['engineer'], 'day laborer' => ['day_laborer']];
    }

    #[DataProvider('minuteTypes')]
    public function test_a_time_cell_stores_the_late_and_overtime_minutes(string $type): void
    {
        $e = $this->employee($type);
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site]])->assertOk();   // Present: the shift's own times give 0 and 0
        $this->day($e)->assertJsonPath('data.late_minutes', 0)->assertJsonPath('data.ot_minutes', 0);

        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:40']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 0);
        $this->bulk([['employee_id' => $e, 'version' => 2, 'site_id' => $this->site, 'out_time' => '18:30']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 90);
    }

    public function test_a_changed_arrival_recomputes_over_a_typed_number_and_a_sent_number_is_stored_as_sent(): void
    {
        $e = $this->employee('engineer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => '08:40', 'late_minutes' => 5, 'ot_minutes' => 30]])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 5)->assertJsonPath('data.ot_minutes', 30);

        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site, 'in_time' => '09:00']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 60)->assertJsonPath('data.ot_minutes', 30);   // overtime follows the departure only
    }

    public function test_a_row_that_sends_an_empty_minutes_value_stores_it_as_sent(): void
    {
        $e = $this->employee('engineer');
        AttendanceDay::create(['employee_id' => $e, 'date' => self::DATE, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00', 'source' => 'entered', 'version' => 1]);   // an old day
        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => '18:30']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 90);

        $this->bulk([['employee_id' => $e, 'version' => 2, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => null, 'ot_minutes' => null]])->assertOk();   // the sheet's Undo
        $this->day($e)->assertJsonPath('data.late_minutes', null)->assertJsonPath('data.ot_minutes', null);
    }

    public function test_overtime_follows_an_arrival_only_when_it_goes_between_empty_and_set(): void
    {
        $e = $this->employee('engineer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => null, 'out_time' => '18:30']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', null)->assertJsonPath('data.ot_minutes', null);
        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:40']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 90);
        $this->bulk([['employee_id' => $e, 'version' => 2, 'site_id' => $this->site, 'in_time' => null]])->assertOk();   // clearing the arrival empties both
        $this->day($e)->assertJsonPath('data.late_minutes', null)->assertJsonPath('data.ot_minutes', null);
    }

    public function test_a_leave_day_or_a_cleared_day_becoming_a_work_day_gets_its_minutes(): void
    {
        [$a, $b] = [$this->employee('engineer'), $this->employee('day_laborer')];
        $this->putDay($a, self::DATE, ['site_id' => $this->leave['sick']])->assertOk();
        $this->bulk([['employee_id' => $a, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:40']])->assertOk();
        $this->day($a)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 0);

        $this->bulk([['employee_id' => $b, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => '18:30']])->assertOk();
        $this->bulk([['employee_id' => $b, 'version' => 1, 'clear' => true]])->assertOk();
        $this->bulk([['employee_id' => $b, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => '18:30']])->assertOk();
        $this->day($b)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', 90);
    }

    public function test_the_employees_own_shift_gives_the_minutes(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['shift_start' => '07:00', 'shift_end' => '15:00']);
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => '07:30', 'out_time' => '16:00']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 30)->assertJsonPath('data.ot_minutes', 60);
    }

    public function test_a_computed_value_above_the_bound_refuses_the_write(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['shift_start' => '06:00', 'shift_end' => '14:00']);
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => '22:30', 'out_time' => '23:00']])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.0.late_minutes')->assertJsonPath('error.details.0.message', __('attendance.rule.out_of_bounds'));
        $this->putDay($e, self::DATE, ['site_id' => $this->site, 'in_time' => '22:30'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'late_minutes');
        $this->assertSame(0, AttendanceDay::where('employee_id', $e)->count());
    }

    public function test_a_write_that_changes_no_time_keeps_the_stored_minutes(): void
    {
        $e = $this->employee('day_laborer');
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => '18:30', 'late_minutes' => 5, 'ot_minutes' => 60]])->assertOk();

        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site, 'in_time' => '08:40', 'note' => 'n']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 5)->assertJsonPath('data.ot_minutes', 60);
        $this->putDay($e, self::DATE, ['version' => 2, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => '18:30', 'late_minutes' => 7])->assertOk()
            ->assertJsonPath('data.late_minutes', 7)->assertJsonPath('data.ot_minutes', 90);   // single-day entry fills an empty value from the times
    }

    public function test_a_day_without_a_departure_stores_no_overtime_and_a_permanent_worker_no_minutes(): void
    {
        [$e, $w] = [$this->employee('engineer'), $this->employee('permanent_worker')];
        $this->bulk([['employee_id' => $e, 'site_id' => $this->site, 'in_time' => '08:40', 'out_time' => null], ['employee_id' => $w, 'site_id' => $this->site, 'in_time' => '08:40']])->assertOk();
        $this->day($e)->assertJsonPath('data.late_minutes', 40)->assertJsonPath('data.ot_minutes', null);
        $this->day($w)->assertJsonPath('data.late_minutes', null)->assertJsonPath('data.ot_minutes', null);   // computed at payroll (§3)
    }

    public function test_re_marking_a_present_day_never_fills_its_missing_times(): void
    {
        $e = $this->employee('engineer');
        $this->putDay($e, self::DATE, ['site_id' => $this->site])->assertOk();   // a day stored without times (single-day entry, history)
        $audits = DB::table('audit_log')->count();

        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->site]])->assertOk()->assertJsonPath('data.0.version', 1);
        $this->assertSame($audits, DB::table('audit_log')->count());
        $this->bulk([['employee_id' => $e, 'version' => 1, 'site_id' => $this->workSite('Site-2')]])->assertOk()->assertJsonPath('data.0.version', 2);
        $this->day($e)->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);   // a move between work sites is not a change of kind
    }

    public function test_a_default_that_would_end_before_it_starts_is_not_filled(): void
    {
        $night = $this->employee('engineer', '2026-01-01', ['shift_start' => '20:00', 'shift_end' => '04:00']);
        $late = $this->employee('day_laborer');
        $worker = $this->employee('permanent_worker', '2026-01-01', ['shift_start' => '20:00', 'shift_end' => '04:00']);
        $this->bulk([['employee_id' => $night, 'site_id' => $this->site], ['employee_id' => $late, 'site_id' => $this->site, 'in_time' => '18:00'], ['employee_id' => $worker, 'site_id' => $this->site]])->assertOk();
        $this->day($night)->assertJsonPath('data.in_time', null)->assertJsonPath('data.out_time', null);
        $this->day($late)->assertJsonPath('data.in_time', '18:00')->assertJsonPath('data.out_time', null);
        $this->day($worker)->assertJsonPath('data.in_time', '20:00')->assertJsonPath('data.out_time', '04:00');   // a permanent worker's shift may cross midnight
    }

    public function test_a_field_row_with_a_time_and_no_site_is_refused_and_nothing_is_written(): void
    {
        [$a, $b] = [$this->employee('engineer'), $this->employee('engineer')];
        $this->bulk([['employee_id' => $a, 'site_id' => $this->site], ['employee_id' => $b, 'site_id' => null, 'in_time' => '09:00']])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.1.site_id')->assertJsonPath('error.details.0.message', __('attendance.rule.site_required'));
        $this->assertSame(0, AttendanceDay::count());
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

    public function test_stale_rows_are_detailed_in_request_order_with_their_message(): void
    {
        [$a, $b, $c] = [$this->employee('engineer'), $this->employee('engineer'), $this->employee('engineer')];
        $this->putDay($b, self::DATE, ['site_id' => $this->site])->assertOk();
        $this->putDay($c, self::DATE, ['site_id' => $this->site])->assertOk();

        // Rows are written in employee_id order (a, b, c), the reverse of the request's.
        foreach (self::CONFLICT_MESSAGE as $locale => $message) {
            $detail = fn (int $i) => ['field' => "rows.{$i}", 'code' => 'concurrency_conflict', 'message' => $message];
            $this->withHeader('Accept-Language', $locale)
                ->bulk([['employee_id' => $c, 'version' => 9, 'site_id' => $this->site], ['employee_id' => $b, 'version' => null, 'site_id' => $this->site], ['employee_id' => $a, 'site_id' => $this->site]])
                ->assertStatus(409)->assertJsonPath('error', ['code' => 'concurrency_conflict', 'message' => $message, 'details' => [$detail(0), $detail(1)]]);
        }
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
            ->assertStatus(423)->assertJsonPath('error.code', 'period_locked')->assertJsonMissingPath('error.details');   // request-wide: it names no row
        $this->assertSame(0, AttendanceDay::where('date', self::DATE)->count());
    }

    public function test_an_employee_not_active_on_the_date_names_their_row(): void
    {
        $active = $this->employee('engineer');
        $hiredLater = $this->employee('engineer', '2026-10-19');

        $this->bulk([['employee_id' => $active, 'site_id' => $this->site], ['employee_id' => $hiredLater, 'site_id' => $this->site]])
            ->assertStatus(422)->assertJsonPath('error.code', 'employee_not_active')->assertJsonPath('error.details.0.field', 'rows.1');
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_a_racing_insert_names_the_row_it_hit(): void
    {
        [$a, $b] = [$this->employee('engineer'), $this->employee('engineer')];
        // Another request inserts $b's day between this request's read and its insert.
        AttendanceDay::creating(function (AttendanceDay $day) use ($b): void {
            if ($day->employee_id === $b) {
                DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $b, 'date' => self::DATE, 'source' => 'entered']);
            }
        });

        foreach (self::CONFLICT_MESSAGE as $locale => $message) {
            $this->withHeader('Accept-Language', $locale)->bulk([['employee_id' => $b, 'site_id' => $this->site], ['employee_id' => $a, 'site_id' => $this->site]])
                ->assertStatus(409)->assertJsonPath('error', [
                    'code' => 'concurrency_conflict', 'message' => $message, 'details' => [['field' => 'rows.0', 'code' => 'concurrency_conflict', 'message' => $message]],
                ]);
        }
        $this->assertSame(0, AttendanceDay::count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->bulk([['employee_id' => $this->employee('engineer'), 'site_id' => $this->site]], '2026-10-21')
            ->assertStatus(422)->assertJsonPath('error.code', 'future_date')->assertJsonMissingPath('error.details');
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
        DB::table('manager_sites')->insert(['manager_id' => $lead->id, 'site_id' => $this->site]);
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
