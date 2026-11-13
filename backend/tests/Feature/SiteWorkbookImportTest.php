<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\SiteWorkbookImport;
use App\Modules\Attendance\Application\SiteWorkbookTemplate;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell\NumericCell;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;
use Tests\XlsxFiles;

/** The site workbook's preview and confirm (calculations §14 W2–W15; spec docs/superpowers/specs/2026-11-12-site-workbook-design.md «Preview, confirm and the token»). */
class SiteWorkbookImportTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;
    use XlsxFiles;

    private const SAT = 'السبت 07-11';

    private const SUN = 'الأحد 08-11';

    private const FRI = 'الجمعة 13-11';

    /** Two made-up 14-digit national IDs (test data, no real person). */
    private const NID = '29501010212345';

    private const NID2 = '29601010212346';

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    private string $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-12 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-S');
        $this->manager = $this->managerOf($this->site);
        $this->other = $this->workSite('Site-T');
    }

    /** W6, W14: anyone picked from the list is recorded at the file's site; late and overtime come from the times (field shift 08:00–17:00). */
    public function test_a_picked_entry_is_previewed_at_this_site(): void
    {
        $worker = $this->homed('permanent_worker', $this->other);
        $engineer = $this->assign($this->employee('engineer'), $this->managerOf($this->other));
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [
            [$this->entry($worker), 'حاضر', '08:30', '18:00'],
            [$this->entry($engineer), 'حاضر', '08:30', '18:00'],
        ]]))->assertOk()->json('data');

        $this->assertSame(['id' => $this->site, 'name_ar' => 'Site-S', 'name_en' => null], $data['site']);
        $this->assertSame(['2026-11-07', '2026-11-13'], [$data['from'], $data['to']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['token']);
        $this->assertSame(['rows' => 2, 'changes' => 2, 'unchanged' => 0, 'errors' => 0, 'conflicts' => 0, 'new_laborers' => 0, 'advances_total' => '0.000000'], $data['summary']);
        $this->assertSame([], $data['new_laborers']);
        $this->assertSame([], $data['errors']);
        $this->assertSame([
            'key' => "{$engineer}/2026-11-07", 'sheet' => self::SAT, 'row' => 4, 'date' => '2026-11-07',
            'employee_id' => $engineer, 'code' => $this->code($engineer), 'name_ar' => 'Engineer-1', 'name_en' => null, 'type' => 'engineer',
            'new_laborer' => false, 'matched_by_national_id' => false, 'conflict_site' => null,
            'from' => null,
            'to' => ['site' => 'Site-S', 'in_time' => '08:30', 'out_time' => '18:00', 'late_minutes' => 30, 'ot_minutes' => 60, 'advance_amount' => null, 'note' => null],
        ], $data['changes'][1]);
        $this->assertSame([$worker, 'permanent_worker', 3], [$data['changes'][0]['employee_id'], $data['changes'][0]['type'], $data['changes'][0]['row']]);
        $this->assertSame(['site' => 'Site-S', 'in_time' => '08:30', 'out_time' => '18:00', 'late_minutes' => null, 'ot_minutes' => null, 'advance_amount' => null, 'note' => null], $data['changes'][0]['to']);
    }

    /** @return array<string, array{string}> */
    public static function unknownEntries(): array
    {
        return ['another code' => ['code'], 'an office employee of another site' => ['office'], 'an archived employee' => ['archived'], 'renamed after the download' => ['renamed']];
    }

    /** W4: a text shaped like an entry that is no entry of the rebuilt name list. */
    #[DataProvider('unknownEntries')]
    public function test_unknown_employee(string $case): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $officeOfT = $this->homed('office', $this->other);
        $entry = $this->entry($laborer);
        $path = $this->download();
        $name = match ($case) {
            'code' => preg_replace('/\d+$/', '99999', $entry),
            'office' => "Office-1 (موظف مكتب) · {$this->code($officeOfT)}",
            'archived' => $entry,
            'renamed' => $entry,
        };
        if ($case === 'archived') {
            DB::table('employees')->where('id', $laborer)->update(['termination_date' => '2026-11-10']);
        }
        if ($case === 'renamed') {
            DB::table('employees')->where('id', $laborer)->update(['name_ar' => 'Renamed']);
        }

        $this->preview($this->fill($path, [self::SAT => [[$name, 'حاضر']]]))->assertOk()
            ->assertJsonPath('data.changes', [])
            ->assertJsonPath('data.errors', [['sheet' => self::SAT, 'row' => 3, 'date' => '2026-11-07', 'name' => $name, 'error' => 'unknown_employee', 'message' => 'الرقم الوظيفي غير موجود.']]);
    }

    /** @return array<string, array{string, list<list<mixed>>, string, int}> sheet, rows (E = the subject's entry, SICK = sick leave), error, its row */
    public static function rowErrors(): array
    {
        return [
            'a status and no name' => [self::SAT, [['', 'حاضر']], 'name_required', 3],
            'a name and no status' => [self::SAT, [['E', '', '08:00', '16:00']], 'status_required', 3],
            'a work site as the status' => [self::SAT, [['E', 'Site-T']], 'unknown_status', 3],
            '25:00' => [self::SAT, [['E', 'حاضر', '08:00', '25:00']], 'bad_time', 3],
            '24:00' => [self::SAT, [['E', 'حاضر', '08:00', '24:00']], 'bad_time', 3],
            '8:60' => [self::SAT, [['E', 'حاضر', '8:60', '16:00']], 'bad_time', 3],
            '9.30' => [self::SAT, [['E', 'حاضر', '9.30', '16:00']], 'bad_time', 3],
            'a negative duration' => [self::SAT, [['E', 'حاضر', '0 08:05:00', '16:00']], 'bad_time', 3],
            'an arrival only' => [self::SAT, [['E', 'حاضر', '08:00']], 'times_need_both', 3],
            'a departure only' => [self::SAT, [['E', 'حاضر', '', '16:00']], 'times_need_both', 3],
            'an advance of 0' => [self::SAT, [['E', 'حاضر', '', '', '0']], 'bad_amount', 3],
            'an advance of -5' => [self::SAT, [['E', 'حاضر', '', '', '-5']], 'bad_amount', 3],
            'an advance of abc' => [self::SAT, [['E', 'حاضر', '', '', 'abc']], 'bad_amount', 3],
            'a 501-character note' => [self::SAT, [['E', 'حاضر', '', '', '', str_repeat('n', 501)]], 'note_too_long', 3],
            'the same entry twice on a sheet' => [self::SAT, [['E', 'حاضر'], ['E', 'SICK']], 'duplicate_row', 4],
            'a leave status with times' => [self::SAT, [['E', 'SICK', '08:00', '16:00']], 'field_not_allowed', 3],
            'a date after today' => [self::FRI, [['E', 'حاضر']], 'future_date', 3],
        ];
    }

    /** W3, W6, W8, W9, W12, W17: each refused row names its sheet, row, date and name; the file's other rows still preview. */
    #[DataProvider('rowErrors')]
    public function test_row_errors(string $sheet, array $rows, string $error, int $row): void
    {
        $subject = $this->homed('day_laborer', $this->site);
        $bystander = $this->homed('day_laborer', $this->site);
        $path = $this->download();
        $sick = (string) Site::whereKey($this->leave['sick'])->value('name_ar');
        $rows = array_map(fn (array $r) => array_map(fn (mixed $v) => match ($v) {
            'E' => $this->entry($subject),
            'SICK' => $sick,
            default => $v,
        }, $r), $rows);
        $bySheet = [$sheet => $rows];
        $bySheet[self::SUN] = [[$this->entry($bystander), 'حاضر']];

        $data = $this->preview($this->fill($path, $bySheet))->assertOk()->json('data');

        $this->assertContains([$bystander, '2026-11-08'], array_map(fn (array $c) => [$c['employee_id'], $c['date']], $data['changes']));
        $this->assertCount(1, $data['errors']);
        $date = $sheet === self::FRI ? '2026-11-13' : '2026-11-07';
        $name = $rows[$row - 3][0] === '' ? null : $this->entry($subject);
        $this->assertSame(['sheet' => $sheet, 'row' => $row, 'date' => $date, 'name' => $name, 'error' => $error], array_diff_key($data['errors'][0], ['message' => true]));
        $this->assertNotSame('', $data['errors'][0]['message']);
        $this->assertStringNotContainsString('attendance.', $data['errors'][0]['message'], 'every code has its message');
        $this->assertSame(count($rows) + 1, $data['summary']['rows']);
    }

    /** W6, W10: «حاضر» is office present (no site); an advance or a note on an office row is refused by the day rules. */
    public function test_office_rows_take_status_and_times_only(): void
    {
        $office = $this->homed('office', $this->site);
        $path = $this->download();
        $file = fn () => $this->fill($path, [
            self::SAT => [[$this->entry($office), 'حاضر', '09:00', '17:00']],
            self::SUN => [[$this->entry($office), 'حاضر', '', '', '100']],
            'الاثنين 09-11' => [[$this->entry($office), 'حاضر', '', '', '', 'a note']],
        ]);

        $data = $this->preview($file())->assertOk()->json('data');

        $this->assertSame(['site' => 'حاضر', 'in_time' => '09:00', 'out_time' => '17:00', 'late_minutes' => null, 'ot_minutes' => null, 'advance_amount' => null, 'note' => null], $data['changes'][0]['to']);
        $this->assertCount(1, $data['changes']);
        $this->assertSame([['2026-11-08', 'field_not_allowed'], ['2026-11-09', 'field_not_allowed']], array_map(fn (array $e) => [$e['date'], $e['error']], $data['errors']));
        $this->preview($file(), 'en')->assertOk()->assertJsonPath('data.changes.0.to.site', 'Present');
    }

    /** W12: the lock is per type and period; the file's range is not a pay period. */
    public function test_a_date_locked_for_engineers_is_open_for_day_laborers(): void
    {
        $engineer = $this->assign($this->employee('engineer'), $this->manager);
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-10-26', 'confirm_missing' => true])->assertSuccessful();

        $data = $this->preview($this->fill($path, [self::SAT => [[$this->entry($engineer), 'حاضر'], [$this->entry($laborer), 'حاضر']]]))->assertOk()->json('data');

        $this->assertSame([$laborer], array_column($data['changes'], 'employee_id'));
        $this->assertSame([[$this->entry($engineer), 'period_locked']], array_map(fn (array $e) => [$e['name'], $e['error']], $data['errors']));
    }

    /** W8: a time Excel stored as a fraction of a day, Arabic-Indic digits and a single-digit hour read as HH:MM. */
    public function test_times_excel_made_are_read(): void
    {
        $first = $this->homed('day_laborer', $this->site);
        $second = $this->homed('day_laborer', $this->site);
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [
            [$this->entry($first), 'حاضر', new NumericCell(0.375), '١٦:٠٠'],
            [$this->entry($second), 'حاضر', '9:05', '17:00'],
        ]]))->assertOk()->json('data');

        $this->assertSame([['09:00', '16:00', 60], ['09:05', '17:00', 65]], array_map(fn (array $c) => [$c['to']['in_time'], $c['to']['out_time'], $c['to']['late_minutes']], $data['changes']));
    }

    /** W13: a day stored at another work site is a conflict, shown replaced by the file's row; one the write refuses is an error, not a conflict to choose on. */
    public function test_a_conflict_is_shown_replaced(): void
    {
        [$laborer, $refused] = [$this->homed('day_laborer', $this->site), $this->homed('day_laborer', $this->site)];
        foreach ([$laborer, $refused] as $id) {
            $this->putDay($id, '2026-11-07', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        }
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [[$this->entry($laborer), 'حاضر', '08:00', '17:00'], [$this->entry($refused), 'حاضر', '17:00', '08:00']]]))
            ->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['conflicts']);
        $this->assertSame([[$this->entry($refused), 'out_before_in']], array_map(fn (array $e) => [$e['name'], $e['error']], $data['errors']));
        $change = $data['changes'][0];
        $this->assertSame(["{$laborer}/2026-11-07", 'Site-T'], [$change['key'], $change['conflict_site']]);
        $this->assertSame(['site' => 'Site-T', 'in_time' => '08:00', 'out_time' => '16:00', 'late_minutes' => 0, 'ot_minutes' => 0, 'advance_amount' => null, 'note' => null], $change['from']);
        $this->assertSame(['site' => 'Site-S', 'in_time' => '08:00', 'out_time' => '17:00', 'late_minutes' => 0, 'ot_minutes' => 0, 'advance_amount' => null, 'note' => null], $change['to']);
    }

    /** W13, W15: a stored day at this site, or on a leave day-type, is an ordinary change; an imported day the file repeats is unchanged. */
    public function test_a_day_here_or_on_leave_is_no_conflict_and_a_repeat_is_unchanged(): void
    {
        [$here, $onLeave] = [$this->homed('day_laborer', $this->site), $this->homed('day_laborer', $this->site)];
        app(AttendanceDays::class)->writeCell((array) app(EmployeeRoster::class)->find($here), '2026-11-07', ['version' => null, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00'], null, 'imported');
        $this->putDay($onLeave, '2026-11-07', ['site_id' => $this->leave['sick']])->assertOk();
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [[$this->entry($here), 'حاضر', '08:00', '17:00'], [$this->entry($onLeave), 'حاضر']]]))->assertOk()->json('data');

        $this->assertSame(['rows' => 2, 'changes' => 1, 'unchanged' => 1, 'errors' => 0, 'conflicts' => 0], array_slice($data['summary'], 0, 5));
        $this->assertSame([$onLeave, null, 'Leave-sick'], [$data['changes'][0]['employee_id'], $data['changes'][0]['conflict_site'], $data['changes'][0]['from']['site']]);
    }

    /** W9: the advances total is the backend's decimal sum over the rows not refused. */
    public function test_the_advances_total_is_the_backends(): void
    {
        [$a, $b, $c] = [$this->homed('day_laborer', $this->site), $this->homed('day_laborer', $this->site), $this->homed('day_laborer', $this->site)];
        $path = $this->download();

        $data = $this->preview($this->fill($path, [
            self::SAT => [[$this->entry($a), 'حاضر', '', '', '500'], [$this->entry($b), 'حاضر', '', '', '200']],
            self::FRI => [[$this->entry($c), 'حاضر', '', '', '900']],
        ]))->assertOk()->json('data');

        $this->assertSame('700.000000', $data['summary']['advances_total']);
        $this->assertSame(['500.000000', '200.000000'], array_column(array_column($data['changes'], 'to'), 'advance_amount'));
        $this->assertSame(['future_date'], array_column($data['errors'], 'error'));
    }

    /** W4: on a row whose name is an entry, the national ID, mobile, residence and wage columns are ignored. */
    public function test_columns_g_to_j_on_an_entry_row_are_ignored(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [[$this->entry($laborer), 'حاضر', '', '', '', '', '123', 'not a mobile', 'No such residence', 'x']]]))
            ->assertOk()->json('data');

        $this->assertSame([[$laborer, false]], array_map(fn (array $c) => [$c['employee_id'], $c['new_laborer']], $data['changes']));
        $this->assertSame([], $data['errors']);
    }

    /** Review Focus 3: a range across a month end (an engineer's 26th → 25th period) maps each sheet to its own date. */
    public function test_a_month_crossing_file_maps_each_sheet_to_its_date(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download('2026-10-26', '2026-11-05');

        $data = $this->preview($this->fill($path, ['الأحد 01-11' => [[$this->entry($laborer, '2026-10-26', '2026-11-05'), 'حاضر']]]))->assertOk()->json('data');

        $this->assertSame([['الأحد 01-11', '2026-11-01']], array_map(fn (array $c) => [$c['sheet'], $c['date']], $data['changes']));
        $this->assertSame(['2026-10-26', '2026-11-05'], [$data['from'], $data['to']]);
    }

    /** @return array<string, array{string}> */
    public static function wholeFileRefusals(): array
    {
        return [
            'a day sheet renamed to no date' => ['renamed'],
            'two sheets for one date' => ['twice'],
            'no lists' => ['no_lists'],
            'a header changed' => ['header'],
            'lists D1 a deleted site' => ['deleted_site'],
            'lists D1 a leave day-type' => ['leave_site'],
            'lists D1 not a uuid' => ['not_uuid'],
            'lists D2 not an ISO date' => ['not_iso'],
            'lists D2 after D3' => ['reversed'],
            'a 32-day range' => ['32_days'],
        ];
    }

    /** W2, W17: anything wrong with the file as a whole refuses it, before any row. */
    #[DataProvider('wholeFileRefusals')]
    public function test_whole_file_refusals(string $case): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download();
        $gone = $this->workSite('Site-gone');
        Site::findOrFail($gone)->delete();
        $lists = fn (int $row, string $value) => fn (string $name, array $rows) => $name === 'lists' ? $this->setCell($rows, $row, 3, $value) : $this->rows($name, $rows, $laborer);

        $file = match ($case) {
            'renamed' => $this->rewrite($path, fn (string $name, array $rows) => $this->rows($name, $rows, $laborer), [self::SAT => 'السبت']),
            'twice' => $this->rewrite($path, fn (string $name, array $rows) => $this->rows($name, $rows, $laborer), [self::SUN => 'نسخة 07-11']),
            'no_lists' => $this->rewrite($path, fn (string $name, array $rows) => $name === 'lists' ? null : $this->rows($name, $rows, $laborer)),
            'header' => $this->rewrite($path, fn (string $name, array $rows) => $name === self::SUN ? $this->setCell($rows, 2, 0, 'Name') : $this->rows($name, $rows, $laborer)),
            'deleted_site' => $this->rewrite($path, $lists(1, $gone)),
            'leave_site' => $this->rewrite($path, $lists(1, $this->leave['sick'])),
            'not_uuid' => $this->rewrite($path, $lists(1, 'Site-S')),
            'not_iso' => $this->rewrite($path, $lists(2, '07/11/2026')),
            'reversed' => $this->rewrite($path, $lists(2, '2026-11-14')),
            '32_days' => $this->rewrite($path, $lists(3, '2026-12-08')),
        };

        $this->preview($file)->assertStatus(422)->assertJsonPath('error.code', 'template_mismatch');
    }

    /** W1, W2: 31 days is the longest range a file may give. */
    public function test_a_31_day_range_is_accepted(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->rewrite($this->download(), fn (string $name, array $rows) => $name === 'lists' ? $this->setCell($rows, 3, 3, '2026-12-07') : $this->rows($name, $rows, $laborer));

        $this->preview($file)->assertOk()->assertJsonPath('data.to', '2026-12-07')->assertJsonPath('data.summary.changes', 1);
    }

    /** W2, W12: a site made inactive since the download is no file error; the write refuses each row at it. */
    public function test_a_site_made_inactive_since_the_download_refuses_its_rows(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download();
        Site::whereKey($this->site)->update(['active' => false]);

        $this->preview($this->fill($path, [self::SAT => [[$this->entry($laborer), 'حاضر']]]))->assertOk()
            ->assertJsonPath('data.changes', [])->assertJsonPath('data.errors.0.error', 'site_inactive');
    }

    /** W16: at most MAX_ROWS data rows per day sheet, not per file. */
    public function test_the_row_cap_is_per_sheet(): void
    {
        $this->app->when(SiteWorkbookImport::class)->needs('$maxRows')->give(2);
        $people = array_map(fn () => $this->homed('day_laborer', $this->site), range(1, 3));
        $path = $this->download();
        $rows = array_map(fn (string $id) => [$this->entry($id), 'حاضر'], $people);

        $this->preview($this->fill($path, [self::SAT => $rows]))->assertStatus(422)->assertJsonPath('error.code', 'too_many_rows');
        $this->preview($this->fill($path, [self::SAT => array_slice($rows, 0, 2), self::SUN => array_slice($rows, 0, 2)]))->assertOk()
            ->assertJsonPath('data.summary.changes', 4);
    }

    /** W15, the side-effect proof: the preview runs the writes and rolls them back; nothing is queued, mailed or notified. */
    public function test_the_preview_saves_and_sends_nothing(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $engineer = $this->assign($this->employee('engineer'), $this->manager);
        $this->putDay($laborer, '2026-11-08', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $path = $this->download();
        $tables = ['attendance_days', 'audit_log', 'employees', 'day_crew_changes', 'employee_wage_proposals'];
        $before = array_map(fn (string $t) => DB::table($t)->count(), $tables);
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->preview($this->fill($path, [
            self::SAT => [[$this->entry($laborer), 'حاضر', '08:00', '17:00', '250'], [$this->entry($engineer), 'حاضر']],
            self::SUN => [[$this->entry($laborer), 'حاضر']],
        ]))->assertOk()->assertJsonPath('data.summary.changes', 3)->assertJsonPath('data.summary.conflicts', 1);

        $this->assertSame($before, array_map(fn (string $t) => DB::table($t)->count(), $tables));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    /** W15: the token covers the stored days the preview read, so a day changed since gives another token, even at the same site. */
    public function test_the_token_covers_the_stored_days_it_read(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $this->putDay($laborer, '2026-11-07', ['site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر']]]);
        $token = fn () => $this->preview($file)->assertOk()->json('data.token');

        $first = $token();
        $this->assertSame($first, $token());
        DB::table('attendance_days')->where(['employee_id' => $laborer, 'date' => '2026-11-07'])->increment('version');   // the version alone
        $this->assertNotSame($first, $token());
    }

    /** Spec «Security notes»: the import writes as HR (no owner), so own scope on either attendance permission is 403 before the file is read. */
    public function test_an_own_scope_caller_gets_403_on_the_preview(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر']]]);
        $callers = [$this->userWith('own', 'own'), $this->userWith('all', 'own'), $this->userWith('own', 'all'), $this->manager];

        foreach ($callers as $caller) {
            $this->actingAs($caller, 'api');
            $this->preview($file)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
            $this->postJson('/api/v1/attendance/site-workbook/preview')->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        }
    }

    /** W14: the confirm stores the day at the file's site as `imported`; nobody's home site, crew or manager changes. */
    public function test_confirm_stores_the_day_here_and_moves_nobody(): void
    {
        $worker = $this->homed('permanent_worker', $this->other);
        $engineer = $this->assign($this->employee('engineer'), $this->managerOf($this->other));
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($worker), 'حاضر', '08:30', '18:00'], [$this->entry($engineer), 'حاضر', '08:30', '18:00']]]);
        $tables = ['employee_site_assignments', 'day_crew_changes', 'employee_manager_assignments'];
        $before = array_map(fn (string $t) => DB::table($t)->orderBy('id')->get()->all(), $tables);

        $this->confirm($file)->assertOk()
            ->assertJsonPath('data', ['applied' => 2, 'unchanged' => 0, 'kept' => 0, 'advances_total' => '0.000000', 'new_laborers' => [], 'errors' => []]);

        $days = DB::table('attendance_days')->get()->keyBy('employee_id');
        $this->assertCount(2, $days);
        $this->assertSame([$this->site, 'imported', '08:30:00', '18:00:00', null, null], $this->stored($days[$worker]));
        $this->assertSame([$this->site, 'imported', '08:30:00', '18:00:00', 30, 60], $this->stored($days[$engineer]));
        $this->assertEquals($before, array_map(fn (string $t) => DB::table($t)->orderBy('id')->get()->all(), $tables));
    }

    /** W13: keep writes nothing, replace overwrites the same day, no choice is choice_required; a choice for a row that is no conflict is ignored. */
    public function test_keep_replace_and_no_choice(): void
    {
        [$kept, $replaced, $unchosen, $plain] = array_map(fn () => $this->homed('day_laborer', $this->site), range(1, 4));
        foreach ([$kept, $replaced, $unchosen] as $id) {
            $this->putDay($id, '2026-11-07', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        }
        $before = DB::table('attendance_days')->get()->keyBy('employee_id');
        $file = $this->fill($this->download(), [self::SAT => array_map(fn (string $id) => [$this->entry($id), 'حاضر', '08:00', '17:00'], [$kept, $replaced, $unchosen, $plain])]);

        $data = $this->confirm($file, ["{$kept}/2026-11-07" => 'keep', "{$replaced}/2026-11-07" => 'replace', "{$plain}/2026-11-07" => 'keep'])->assertOk()->json('data');

        $this->assertSame([2, 0, 1], [$data['applied'], $data['unchanged'], $data['kept']]);
        $this->assertSame([[$this->entry($unchosen), 'choice_required']], array_map(fn (array $e) => [$e['name'], $e['error']], $data['errors']));
        $this->assertStringNotContainsString('attendance.', $data['errors'][0]['message']);
        $after = DB::table('attendance_days')->get()->keyBy('employee_id');
        $this->assertEquals([$before[$kept], $before[$unchosen]], [$after[$kept], $after[$unchosen]]);
        $this->assertSame([$before[$replaced]->id, $this->site, $before[$replaced]->version + 1], [$after[$replaced]->id, $after[$replaced]->site_id, $after[$replaced]->version]);
        $this->assertSame($this->site, $after[$plain]->site_id);
    }

    /** W11: HR's confirm approves the overtime the times give, over a site manager's day stored with 0 and the same times. */
    public function test_confirm_approves_overtime_over_a_site_managers_day(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        app(AttendanceDays::class)->bulk('2026-11-07', [['employee_id' => $laborer, 'version' => null, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '18:00']], (string) $this->manager->id, 'manager');
        $day = fn () => DB::table('attendance_days')->where(['employee_id' => $laborer, 'date' => '2026-11-07'])->first(['ot_minutes', 'source']);
        $this->assertEquals((object) ['ot_minutes' => 0, 'source' => 'manager'], $day());

        $this->confirm($this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر', '08:00', '18:00']]]))->assertOk()->assertJsonPath('data.applied', 1);

        $this->assertEquals((object) ['ot_minutes' => 60, 'source' => 'imported'], $day());
    }

    /** W12, W13 (B3 review M2): a conflict row the write refuses confirms, with no choice sent, as that refusal, as the preview showed it; nothing is saved. */
    public function test_a_conflict_the_write_refuses_confirms_as_its_refusal(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $this->putDay($laborer, '2026-11-07', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر', '17:00', '08:00']]]);
        [$days, $audits] = [DB::table('attendance_days')->get()->all(), DB::table('audit_log')->count()];
        $refused = fn (TestResponse $r) => array_map(fn (array $e) => [$e['name'], $e['error']], $r->json('data.errors'));

        $preview = $this->preview($file)->assertOk()->assertJsonPath('data.summary.conflicts', 0);
        $confirm = $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 0)->assertJsonPath('data.kept', 0);

        $this->assertSame([[$this->entry($laborer), 'out_before_in']], $refused($preview));
        $this->assertSame($refused($preview), $refused($confirm));
        $this->assertEquals([$days, $audits], [DB::table('attendance_days')->get()->all(), DB::table('audit_log')->count()]);
    }

    /** W9: the advance is stored; the total is the sum on the rows the confirm saved, not on a kept or a refused row. */
    public function test_the_advance_is_stored(): void
    {
        [$a, $b, $c, $kept] = array_map(fn () => $this->homed('day_laborer', $this->site), range(1, 4));
        $this->putDay($kept, '2026-11-07', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $file = $this->fill($this->download(), [
            self::SAT => [[$this->entry($a), 'حاضر', '', '', '500'], [$this->entry($b), 'حاضر', '', '', '200'], [$this->entry($kept), 'حاضر', '', '', '300']],
            self::FRI => [[$this->entry($c), 'حاضر', '', '', '900']],
        ]);

        $this->confirm($file, ["{$kept}/2026-11-07" => 'keep'])->assertOk()
            ->assertJsonPath('data.advances_total', '700.000000')->assertJsonPath('data.applied', 2)->assertJsonPath('data.kept', 1);

        $this->assertSame('500.000000', DB::table('attendance_days')->where(['employee_id' => $a, 'date' => '2026-11-07'])->value('advance_amount'));
    }

    /** W15: a stored day changed after the preview gives another token: 409 preview_stale, and nothing is saved. */
    public function test_a_day_changed_after_the_preview_is_409_and_saves_nothing(): void
    {
        [$a, $b] = [$this->homed('day_laborer', $this->site), $this->homed('day_laborer', $this->site)];
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($a), 'حاضر'], [$this->entry($b), 'حاضر']]]);
        $token = $this->preview($file)->assertOk()->json('data.token');
        $this->putDay($a, '2026-11-07', ['site_id' => $this->leave['sick']])->assertOk();
        [$days, $audits] = [DB::table('attendance_days')->orderBy('id')->get()->all(), DB::table('audit_log')->count()];

        $this->confirm($file, [], $token)->assertStatus(409)->assertJsonPath('error.code', 'preview_stale')
            ->assertJsonPath('error.message', 'تغيّر الحضور المسجّل بعد المعاينة. اعرض المعاينة مرة أخرى.');

        $this->assertEquals([$days, $audits], [DB::table('attendance_days')->orderBy('id')->get()->all(), DB::table('audit_log')->count()]);
    }

    /** W15: the first confirm made the days imported, so the same file again changes nothing; its advances are still the total. */
    public function test_uploading_the_same_file_again_saves_nothing(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر', '08:00', '17:00', '500']]]);
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 1);
        [$days, $audits] = [DB::table('attendance_days')->get()->all(), DB::table('audit_log')->count()];

        $this->confirm($file)->assertOk()
            ->assertJsonPath('data', ['applied' => 0, 'unchanged' => 1, 'kept' => 0, 'advances_total' => '500.000000', 'new_laborers' => [], 'errors' => []]);

        $this->assertEquals([$days, $audits], [DB::table('attendance_days')->get()->all(), DB::table('audit_log')->count()]);
    }

    /** W12: a row refused by the write path or the guard is listed; the file's other rows are saved. */
    public function test_a_refused_row_does_not_stop_the_others(): void
    {
        $office = $this->homed('office', $this->site);      // created first, so written first (employee_id order)
        $laborer = $this->homed('day_laborer', $this->site);
        $later = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [
            self::SAT => [[$this->entry($office), 'حاضر', '', '', '100'], [$this->entry($laborer), 'حاضر']],
            self::FRI => [[$this->entry($later), 'حاضر']],
        ]);

        $data = $this->confirm($file)->assertOk()->json('data');

        $this->assertSame(1, $data['applied']);
        $this->assertSame([['2026-11-07', 'field_not_allowed'], ['2026-11-13', 'future_date']], array_map(fn (array $e) => [$e['date'], $e['error']], $data['errors']));
        $this->assertSame([$laborer], DB::table('attendance_days')->pluck('employee_id')->all());
    }

    /** Spec «Endpoints»: the token is required and a choice is keep or replace (422 validation_failed); nothing is written. */
    public function test_confirm_validates_token_and_choices(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر']]]);
        $token = $this->preview($file)->assertOk()->json('data.token');

        $this->post('/api/v1/attendance/site-workbook/confirm', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->confirm($file, ["{$laborer}/2026-11-07" => 'maybe'], $token)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, DB::table('attendance_days')->count());
    }

    /** Spec «Security notes»: own scope on either attendance permission is 403 on the confirm, and nothing is written. */
    public function test_an_own_scope_caller_gets_403_on_the_confirm(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر']]]);
        $token = $this->preview($file)->assertOk()->json('data.token');

        foreach ([$this->userWith('own', 'own'), $this->userWith('all', 'own'), $this->userWith('own', 'all'), $this->manager] as $caller) {
            $this->actingAs($caller, 'api');
            $this->confirm($file, [], $token)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
            // Before validation: no file and no token is still a 403, not a 422.
            $this->postJson('/api/v1/attendance/site-workbook/confirm')->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        }
        $this->assertSame(0, DB::table('attendance_days')->count());
    }

    /** W5: a typed name is one new day laborer per national ID, hired on their earliest date in the file, male, on this site's crew that day only. */
    public function test_a_new_laborer_is_one_employee_hired_on_the_earliest_date(): void
    {
        $residence = (string) Residence::create(['name_ar' => 'Residence-R'])->getKey();
        $file = $this->fill($this->download(), [
            self::SUN => [['New Laborer', 'حاضر', '08:00', '18:00', '', '', self::NID, '٠١٠١٢٣٤٥٦٧٨', 'Residence-R', '350']],   // mobile in Arabic-Indic digits
            self::SAT => [$this->newRow('New Laborer', self::NID)],
        ]);

        $data = $this->preview($file)->assertOk()->json('data');

        $this->assertSame([[
            'name_ar' => 'New Laborer', 'national_id' => self::NID, 'phone' => '01012345678', 'residence' => 'Residence-R', 'proposed_wage' => '350.000000',
            'gender' => 'male', 'first_date' => '2026-11-07', 'days' => 2,
        ]], $data['new_laborers']);
        $this->assertSame(['rows' => 2, 'changes' => 2, 'unchanged' => 0, 'errors' => 0, 'conflicts' => 0, 'new_laborers' => 1], array_slice($data['summary'], 0, 6));
        $this->assertSame([], $data['errors']);
        $this->assertSame([
            'key' => null, 'sheet' => self::SUN, 'row' => 3, 'date' => '2026-11-08', 'employee_id' => null, 'code' => null, 'name_ar' => 'New Laborer', 'name_en' => null,
            'type' => 'day_laborer', 'new_laborer' => true, 'matched_by_national_id' => false, 'conflict_site' => null, 'from' => null,
            'to' => ['site' => 'Site-S', 'in_time' => '08:00', 'out_time' => '18:00', 'late_minutes' => 0, 'ot_minutes' => 60, 'advance_amount' => null, 'note' => null],
        ], $data['changes'][1]);
        $this->assertSame([self::SAT, true], [$data['changes'][0]['sheet'], $data['changes'][0]['new_laborer']]);

        $made = $this->confirm($file)->assertOk()->json('data');

        $e = DB::table('employees')->where('national_id', self::NID)->first();
        $this->assertNotNull($e);
        $this->assertSame([['employee_id' => $e->id, 'code' => (int) $e->code, 'name_ar' => 'New Laborer', 'proposal' => true]], $made['new_laborers']);
        $this->assertSame([2, []], [$made['applied'], $made['errors']]);
        $this->assertSame(['male', '2026-11-07', '01012345678', $residence], [$e->gender, $e->hire_date, $e->phone, $e->residence_id]);
        $this->assertEquals([(object) ['type' => 'day_laborer', 'effective_from' => '2026-11-07']], DB::table('employee_type_changes')->where('employee_id', $e->id)->get(['type', 'effective_from'])->all());
        $this->assertSame(0, DB::table('employee_site_assignments')->where('employee_id', $e->id)->count());
        $this->assertEquals([(object) ['date' => '2026-11-07', 'site_id' => $this->site]], DB::table('day_crew_changes')->where('employee_id', $e->id)->get(['date', 'site_id'])->all());
        $this->assertEquals([(object) ['daily_wage' => '350.000000', 'status' => 'pending']], DB::table('employee_wage_proposals')->where('employee_id', $e->id)->get(['daily_wage', 'status'])->all());
        $this->assertSame([['2026-11-07', $this->site, 'imported'], ['2026-11-08', $this->site, 'imported']], DB::table('attendance_days')->where('employee_id', $e->id)->orderBy('date')
            ->get()->map(fn (object $d) => [$d->date, $d->site_id, $d->source])->all());
    }

    /** W5, spec «Differences» 2: a blank proposed wage makes no proposal; HR sets the wage on the employee's page. */
    public function test_a_blank_wage_makes_no_proposal(): void
    {
        $file = $this->fill($this->download(), [self::SAT => [$this->newRow('New Laborer', self::NID)]]);

        $this->preview($file)->assertOk()->assertJsonPath('data.new_laborers.0.proposed_wage', null);
        $this->confirm($file)->assertOk()->assertJsonPath('data.new_laborers.0.proposal', false);

        $this->assertSame(1, DB::table('employees')->where('national_id', self::NID)->count());
        $this->assertSame(0, DB::table('employee_wage_proposals')->count());
    }

    /** W5, Review Focus 4: rows that disagree take each value from the first non-blank row by sheet date, never by sheet position. */
    public function test_the_first_non_blank_value_in_date_order_wins(): void
    {
        Residence::create(['name_ar' => 'Residence-R']);
        $file = $this->fill($this->download(), [
            self::SUN => [$this->newRow('New Laborer', self::NID, '01011111111', 'Residence-R', '300')],
            self::SAT => [$this->newRow('New Laborer', self::NID, '01022222222')],
        ], [self::SUN, self::SAT]);

        $this->preview($file)->assertOk()->assertJsonPath('data.new_laborers', [[
            'name_ar' => 'New Laborer', 'national_id' => self::NID, 'phone' => '01022222222', 'residence' => 'Residence-R', 'proposed_wage' => '300.000000',
            'gender' => 'male', 'first_date' => '2026-11-07', 'days' => 2,
        ]]);
    }

    /** @return array<string, array{string}> */
    public static function nationalIdErrors(): array
    {
        return [
            'missing' => ['missing'], '13 digits' => ['13_digits'], 'an archived employee\'s' => ['archived'], 'a soft-deleted employee\'s' => ['deleted'],
            'an office employee of another site\'s' => ['office'], 'another name on a later row' => ['mismatch'], 'twice on one sheet' => ['twice'],
        ];
    }

    /** W5, W4, spec «Security notes»: a national ID that cannot make or find the person refuses the row, and its message never holds the ID. */
    #[DataProvider('nationalIdErrors')]
    public function test_national_id_errors(string $case): void
    {
        $path = $this->download();
        $holder = match ($case) {
            'archived' => $this->employee('day_laborer', '2026-01-01', ['termination_date' => '2026-11-10']),
            'deleted' => $this->homed('day_laborer', $this->site),
            'office' => $this->homed('office', $this->other),
            default => null,
        };
        if ($holder !== null) {
            DB::table('employees')->where('id', $holder)->update(['national_id' => self::NID, ...($case === 'deleted' ? ['deleted_at' => now()] : [])]);
        }
        [$bySheet, $error, $sheet, $row] = match ($case) {
            'missing' => [[self::SAT => [$this->newRow('New Laborer', null)]], 'national_id_required', self::SAT, 3],
            '13_digits' => [[self::SAT => [$this->newRow('New Laborer', substr(self::NID, 1))]], 'national_id_required', self::SAT, 3],
            'archived', 'deleted', 'office' => [[self::SAT => [$this->newRow('New Laborer', self::NID)]], 'national_id_taken', self::SAT, 3],
            'mismatch' => [[self::SAT => [$this->newRow('New Laborer', self::NID)], self::SUN => [$this->newRow('Other Name', self::NID)]], 'national_id_name_mismatch', self::SUN, 3],
            'twice' => [[self::SAT => [$this->newRow('New Laborer', self::NID), $this->newRow('New Laborer', self::NID)]], 'duplicate_row', self::SAT, 4],
        };

        $response = $this->preview($this->fill($path, $bySheet))->assertOk();

        $e = $response->json('data.errors');
        $this->assertSame([[$sheet, $row, $error]], array_map(fn (array $x) => [$x['sheet'], $x['row'], $x['error']], $e));
        $this->assertStringNotContainsString(self::NID, $e[0]['message']);
        $this->assertStringNotContainsString('attendance.', $e[0]['message'], 'every code has its message');
        if (in_array($case, ['archived', 'deleted', 'office'], true)) {
            $this->assertStringNotContainsString(self::NID, (string) $response->getContent(), 'a taken ID is never shown');
        }
        $this->assertSame(in_array($case, ['mismatch', 'twice'], true) ? 1 : 0, $response->json('data.summary.new_laborers'));
    }

    /** @return array<string, array{string}> */
    public static function listedNationalIds(): array
    {
        return ['as typed' => [self::NID], 'in Arabic-Indic digits' => [strtr(self::NID, ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'])]];
    }

    /** W5, Review Focus 2: the national ID of someone in the name list is that employee, flagged; columns H–J are ignored and nobody is created. */
    #[DataProvider('listedNationalIds')]
    public function test_a_listed_employees_national_id_is_that_employee(string $typed): void
    {
        $laborer = $this->homed('day_laborer', $this->other);
        DB::table('employees')->where('id', $laborer)->update(['national_id' => self::NID]);
        $file = $this->fill($this->download(), [self::SAT => [$this->newRow('Typed Name', $typed, 'not a mobile', 'No such residence', 'x')]]);
        $employees = DB::table('employees')->count();

        $data = $this->preview($file)->assertOk()->json('data');

        $this->assertSame([], $data['errors']);
        $this->assertSame([], $data['new_laborers']);
        $this->assertSame([["{$laborer}/2026-11-07", $laborer, 'Laborer-1', false, true]], array_map(fn (array $c) => [$c['key'], $c['employee_id'], $c['name_ar'], $c['new_laborer'], $c['matched_by_national_id']], $data['changes']));
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 1)->assertJsonPath('data.new_laborers', []);
        $this->assertSame($employees, DB::table('employees')->count());
    }

    /** @return array<string, array{list<?string>, string}> mobile, residence, wage; the error */
    public static function newLaborerCells(): array
    {
        return [
            'a 10-digit mobile' => [['0101234567', null, null], 'bad_mobile'],
            'an unknown residence' => [[null, 'Residence-X', null], 'unknown_residence'],
            'a residence name two residences share' => [[null, 'residence-r', null], 'unknown_residence'],
            'a wage of 0' => [[null, null, '0'], 'bad_amount'],
            'a wage of x' => [[null, null, 'x'], 'bad_amount'],
        ];
    }

    /**
     * W5: a new laborer's mobile, residence and proposed wage; a refused row hires nobody.
     *
     * @param  list<?string>  $cells
     */
    #[DataProvider('newLaborerCells')]
    public function test_new_laborer_cells(array $cells, string $error): void
    {
        Residence::create(['name_ar' => 'Residence-R']);
        Residence::create(['name_ar' => 'RESIDENCE-R']);
        $file = $this->fill($this->download(), [self::SAT => [$this->newRow('New Laborer', self::NID, ...$cells)]]);

        $this->preview($file)->assertOk()->assertJsonPath('data.new_laborers', [])
            ->assertJsonPath('data.errors.0.error', $error)->assertJsonCount(1, 'data.errors');
    }

    /** W5: a new laborer's name longer than employees.name_ar holds (255 characters) is a row error, never a database error; 255 is accepted. */
    public function test_a_new_laborers_name_over_255_characters_is_name_too_long(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $long = str_repeat('ن', 256);       // 256 characters, 512 bytes: counted in characters
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر'], $this->newRow($long, self::NID), $this->newRow(str_repeat('ن', 255), self::NID2)]]);

        $data = $this->preview($file)->assertOk()->json('data');

        $this->assertSame([[$long, 'name_too_long']], array_map(fn (array $e) => [$e['name'], $e['error']], $data['errors']));
        $this->assertStringNotContainsString('attendance.', $data['errors'][0]['message'], 'the code has its message');
        $this->assertSame([self::NID2], array_column($data['new_laborers'], 'national_id'));
        $this->assertSame(2, $data['summary']['changes']);
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 2)->assertJsonPath('data.errors.0.error', 'name_too_long');
        $this->assertSame(0, DB::table('employees')->where('national_id', self::NID)->count());
    }

    /** Spec «Endpoints and permissions»: without employee.create at all scope a new laborer's row is refused; the file's other rows are saved. */
    public function test_new_laborer_not_allowed_without_employee_create_at_all_scope(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر'], $this->newRow('New Laborer', self::NID)]]);
        $this->actingAs($this->userWith('all', 'all'), 'api');

        $data = $this->confirm($file)->assertOk()->json('data');

        $this->assertSame([1, []], [$data['applied'], $data['new_laborers']]);
        $this->assertSame([['New Laborer', 'new_laborer_not_allowed']], array_map(fn (array $e) => [$e['name'], $e['error']], $data['errors']));
        $this->assertSame([$laborer], DB::table('attendance_days')->pluck('employee_id')->all());
        $this->assertSame(0, DB::table('employees')->where('national_id', self::NID)->count());
    }

    /** Review Focus 1 (plan «Pinned here»): rows after today or in a locked day-laborer period are refused before the hire date is chosen. */
    public function test_rows_after_today_or_in_a_locked_period_do_not_hire(): void
    {
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-10-16', 'confirm_missing' => true])->assertCreated();
        [$fri, $sun, $later] = ['الجمعة 30-10', 'الأحد 01-11', 'الجمعة 13-11'];
        $file = $this->fill($this->download('2026-10-30', '2026-11-13'), [
            $fri => [$this->newRow('Hired', self::NID, '01011111111'), $this->newRow('Never', self::NID2)],
            $sun => [$this->newRow('Hired', self::NID, '01022222222')],
            $later => [$this->newRow('Hired', self::NID), $this->newRow('Never', self::NID2)],
        ]);

        $data = $this->preview($file)->assertOk()->json('data');

        $this->assertSame([['Hired', '01022222222', '2026-11-01', 1]], array_map(fn (array $l) => [$l['name_ar'], $l['phone'], $l['first_date'], $l['days']], $data['new_laborers']));
        $this->assertSame([[$fri, 3, 'period_locked'], [$fri, 4, 'period_locked'], [$later, 3, 'future_date'], [$later, 4, 'future_date']],
            array_map(fn (array $e) => [$e['sheet'], $e['row'], $e['error']], $data['errors']));
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 1);
        $e = DB::table('employees')->where('national_id', self::NID)->first();
        $this->assertNotNull($e);
        $this->assertSame('2026-11-01', $e->hire_date);
        $this->assertSame(['2026-11-01'], DB::table('day_crew_changes')->where('employee_id', $e->id)->pluck('date')->all());
        $this->assertSame(['2026-11-01'], DB::table('employee_type_changes')->where('employee_id', $e->id)->pluck('effective_from')->all());
        $this->assertSame(['2026-11-01'], DB::table('attendance_days')->where('employee_id', $e->id)->pluck('date')->all());
        $this->assertSame(0, DB::table('employees')->where('national_id', self::NID2)->count());
    }

    /** W15: the preview creates the laborer and rolls them back, the employee ID counter included; it notifies nobody. */
    public function test_the_preview_with_a_new_laborer_saves_nothing(): void
    {
        $file = $this->fill($this->download(), [self::SAT => [$this->newRow('New Laborer', self::NID, null, null, '350')]]);
        $tables = ['employees', 'employee_type_changes', 'day_crew_changes', 'employee_wage_proposals', 'attendance_days', 'audit_log'];
        $before = [...array_map(fn (string $t) => DB::table($t)->count(), $tables), DB::table('employee_code_counter')->value('last_issued')];
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->preview($file)->assertOk()->assertJsonPath('data.summary.new_laborers', 1)->assertJsonPath('data.summary.changes', 1);

        $this->assertSame($before, [...array_map(fn (string $t) => DB::table($t)->count(), $tables), DB::table('employee_code_counter')->value('last_issued')]);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    /** W15: after a confirm the file's national ID resolves to the employee it created, so the same file again creates and changes nothing. */
    public function test_uploading_again_after_a_confirm_creates_nobody(): void
    {
        $file = $this->fill($this->download(), [
            self::SAT => [$this->newRow('New Laborer', self::NID, null, null, '350')],
            self::SUN => [['New Laborer', 'حاضر', '08:00', '18:00', '', '', self::NID]],
        ]);
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 2);
        $employees = DB::table('employees')->count();

        $this->preview($file)->assertOk()->assertJsonPath('data.changes', [])->assertJsonPath('data.new_laborers', [])->assertJsonPath('data.summary.unchanged', 2);
        $this->confirm($file)->assertOk()->assertJsonPath('data.applied', 0)->assertJsonPath('data.new_laborers', []);

        $this->assertSame($employees, DB::table('employees')->count());
    }

    /** W15: the token covers each new national ID's resolution, so someone given that ID since the preview makes the confirm stale. */
    public function test_a_national_id_taken_after_the_preview_is_409_and_saves_nothing(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر'], $this->newRow('New Laborer', self::NID)]]);
        $token = $this->preview($file)->assertOk()->json('data.token');
        $this->employee('office', '2026-01-01', ['national_id' => self::NID]);
        $employees = DB::table('employees')->count();

        $this->confirm($file, [], $token)->assertStatus(409)->assertJsonPath('error.code', 'preview_stale');

        $this->assertSame([0, $employees], [DB::table('attendance_days')->count(), DB::table('employees')->count()]);
    }

    /** W5, W15: a national ID inserted by another request between the read and the create is a 409 for the whole file; nothing is saved. */
    public function test_a_racing_national_id_is_a_conflict_for_the_whole_file(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        // The first new laborer is created before the second one's insert fails, so the whole transaction must roll back.
        $file = $this->fill($this->download(), [self::SAT => [[$this->entry($laborer), 'حاضر'], $this->newRow('First New', self::NID2), $this->newRow('New Laborer', self::NID)]]);
        $token = $this->preview($file)->assertOk()->json('data.token');
        // Another request commits an employee with that ID just before this one's insert.
        Employee::creating(function (Employee $e): void {
            if ($e->national_id === self::NID) {
                DB::table('employees')->insert(['id' => (string) Str::uuid7(), 'code' => 999999, 'name_ar' => 'Rival', 'gender' => 'male', 'national_id' => self::NID]);
            }
        });
        $before = [DB::table('employees')->count(), DB::table('employee_code_counter')->value('last_issued'), DB::table('audit_log')->count()];

        foreach ([$this->confirm($file, [], $token), $this->preview($file)] as $response) {
            $response->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
            $this->assertStringNotContainsString(self::NID, (string) $response->getContent());
        }

        $this->assertSame(0, DB::table('attendance_days')->count());
        $this->assertSame($before, [DB::table('employees')->count(), DB::table('employee_code_counter')->value('last_issued'), DB::table('audit_log')->count()]);
    }

    /**
     * A new day laborer's row: the typed name, a status, then from column G the national ID, mobile, residence and proposed wage.
     *
     * @return list<string>
     */
    private function newRow(string $name, ?string $nationalId, ?string $mobile = null, ?string $residence = null, ?string $wage = null): array
    {
        return [$name, 'حاضر', '', '', '', '', $nationalId ?? '', $mobile ?? '', $residence ?? '', $wage ?? ''];
    }

    /** An employee of $type employed from 2026-01-01 whose home site is $siteId (office: their dated site). */
    private function homed(string $type, string $siteId): string
    {
        $id = $this->employee($type);
        $this->homeSite($id, $siteId, '2026-01-01');

        return $id;
    }

    /** Assigns the engineer to the manager from 10-01; returns the employee id. */
    private function assign(string $employeeId, User $manager): string
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => '2026-10-01']);

        return $employeeId;
    }

    /** The employee's entry in the site's name list for the range, as the download writes it. */
    private function entry(string $employeeId, string $from = '2026-11-07', string $to = '2026-11-13'): string
    {
        foreach (app(SiteWorkbookTemplate::class)->names($this->site, $from, $to) as $n) {
            if ($n['employee']['id'] === $employeeId) {
                return $n['entry'];
            }
        }
        $this->fail("{$employeeId} is not in the name list");
    }

    private function code(string $employeeId): int
    {
        return (int) DB::table('employees')->where('id', $employeeId)->value('code');
    }

    /** Downloads the site's workbook for the range, as HR; returns the file's temp path. */
    private function download(string $from = '2026-11-07', string $to = '2026-11-13'): string
    {
        $response = $this->get('/api/v1/attendance/site-workbook?'.http_build_query(['site_id' => $this->site, 'from' => $from, 'to' => $to]))->assertOk();
        $path = $this->temp();
        file_put_contents($path, $this->sent($response));

        return $path;
    }

    /**
     * The downloaded file with these rows placed from Excel row 3 of the named day sheets; $order puts sheets first.
     *
     * @param  array<string, list<list<mixed>>>  $bySheet
     * @param  list<string>  $order
     */
    private function fill(string $path, array $bySheet, array $order = []): UploadedFile
    {
        return $this->rewrite($path, fn (string $name, array $rows) => isset($bySheet[$name]) ? [...array_slice($rows, 0, 2), ...$bySheet[$name]] : $rows, order: $order);
    }

    /**
     * One valid row on the first day sheet, so a refusal is the file's and not an empty upload's.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<list<mixed>>
     */
    private function rows(string $name, array $rows, string $employeeId): array
    {
        return $name === self::SAT ? [...array_slice($rows, 0, 2), [$this->entry($employeeId), 'حاضر']] : $rows;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<list<mixed>>
     */
    private function setCell(array $rows, int $row, int $column, string $value): array
    {
        $rows[$row - 1] = array_pad($rows[$row - 1], $column + 1, null);
        $rows[$row - 1][$column] = $value;

        return $rows;
    }

    private function preview(UploadedFile $file, string $locale = 'ar'): TestResponse
    {
        return $this->post('/api/v1/attendance/site-workbook/preview', ['file' => $file], ['Accept' => 'application/json', 'Accept-Language' => $locale]);
    }

    /**
     * Confirms the file with these choices and the token, by default the one a preview gives now.
     *
     * @param  array<string, string>  $choices
     */
    private function confirm(UploadedFile $file, array $choices = [], ?string $token = null): TestResponse
    {
        $token ??= (string) $this->preview($file)->assertOk()->json('data.token');

        return $this->post('/api/v1/attendance/site-workbook/confirm', ['file' => $file, 'token' => $token, 'choices' => $choices], ['Accept' => 'application/json', 'Accept-Language' => 'ar']);
    }

    /** @return list<mixed> a stored day's site, source, times, late and overtime minutes */
    private function stored(object $day): array
    {
        return [$day->site_id, $day->source, $day->in_time, $day->out_time, $day->late_minutes, $day->ot_minutes];
    }
}
