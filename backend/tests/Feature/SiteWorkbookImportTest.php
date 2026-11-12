<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\SiteWorkbookImport;
use App\Modules\Attendance\Application\SiteWorkbookTemplate;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell\NumericCell;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;
use Tests\XlsxFiles;

/** The site workbook's preview (calculations §14 W2–W15; spec docs/superpowers/specs/2026-11-12-site-workbook-design.md «Preview»). */
class SiteWorkbookImportTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;
    use XlsxFiles;

    private const SAT = 'السبت 07-11';

    private const SUN = 'الأحد 08-11';

    private const FRI = 'الجمعة 13-11';

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

    /** W13: a day stored at another work site is a conflict, shown replaced by the file's row. */
    public function test_a_conflict_is_shown_replaced(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $this->putDay($laborer, '2026-11-07', ['site_id' => $this->other, 'in_time' => '08:00', 'out_time' => '16:00'])->assertOk();
        $path = $this->download();

        $data = $this->preview($this->fill($path, [self::SAT => [[$this->entry($laborer), 'حاضر', '08:00', '17:00']]]))->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['conflicts']);
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

    /** W15: the token covers the stored days the preview read, so a day changed since gives another token. */
    public function test_the_token_covers_the_stored_days_it_read(): void
    {
        $laborer = $this->homed('day_laborer', $this->site);
        $path = $this->download();
        $file = fn () => $this->fill($path, [self::SAT => [[$this->entry($laborer), 'حاضر']]]);
        $token = fn () => $this->preview($file())->assertOk()->json('data.token');

        $first = $token();
        $this->assertSame($first, $token());
        $this->putDay($laborer, '2026-11-07', ['site_id' => $this->leave['sick']])->assertOk();
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
}
