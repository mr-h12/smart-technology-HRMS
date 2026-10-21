<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceImport;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceImportTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const STARTS = ['engineer' => '2026-09-26', 'office' => '2026-10-01'];

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    private string $eng;

    private User $hr;

    /** @var list<string> temp files to remove in tearDown */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->hr = $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-1');
        $this->eng = $this->employee('engineer');
        $this->holiday('2026-10-06');
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_round_trip_download_edit_upload(): void
    {
        $file = $this->edit($this->download(), [$this->code($this->eng) => [
            '2026-10-01' => 'Site-1', '2026-10-03' => $this->leaveName('absent'), '2026-10-04' => 'Site-1', '2026-10-05' => 'No-Such-Site',
        ]]);

        // 8 cells: the 4 edits, plus the derived Fridays 10-02, 10-09, 10-16 and the holiday 10-06, which are unchanged.
        $this->upload('preview', $file)->assertOk()
            ->assertJsonPath('data.summary', ['cells' => 8, 'changes' => 3, 'unchanged' => 4, 'errors' => 1])
            ->assertJsonPath('data.changes.0', [
                'row' => 2, 'column' => 'H', 'employee_id' => $this->eng, 'code' => (int) $this->code($this->eng), 'name_ar' => 'Engineer-1',
                'name_en' => null, 'date' => '2026-10-01', 'from' => null, 'to' => 'Site-1',
            ])
            ->assertJsonPath('data.changes.1.to', $this->leaveName('absent'))
            ->assertJsonPath('data.errors', [[
                'row' => 2, 'column' => $this->column('2026-10-05'), 'code' => (int) $this->code($this->eng), 'date' => '2026-10-05',
                'error' => 'unknown_site', 'message' => 'الموقع أو نوع اليوم غير موجود.',
            ]]);
        $this->assertSame(0, AttendanceDay::count(), 'the preview saves nothing');

        $this->upload('confirm', $file)->assertOk()
            ->assertJsonPath('data.applied', 3)->assertJsonPath('data.unchanged', 4)->assertJsonPath('data.errors.0.error', 'unknown_site');
        $this->assertSame(['imported'], AttendanceDay::distinct()->pluck('source')->all());
        $this->assertSame(3, DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'CREATED'])->count());

        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.applied', 0)->assertJsonPath('data.unchanged', 7);
        $this->assertSame(3, AttendanceDay::count());
    }

    public function test_an_untouched_template_writes_nothing(): void
    {
        $this->employee('engineer', '2026-10-10');
        $file = $this->file($this->download());

        // Engineer-1: the derived 10-02, 10-06, 10-09, 10-16; Engineer-2 (hired 10-10): 10-16.
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary', ['cells' => 5, 'changes' => 0, 'unchanged' => 5, 'errors' => 0]);
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data', ['applied' => 0, 'unchanged' => 5, 'errors' => []]);
        $this->assertSame(0, AttendanceDay::withTrashed()->count());
    }

    public function test_a_cell_changed_since_the_download_is_a_conflict_not_an_overwrite(): void
    {
        $site2 = $this->workSite('Site-2');
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site])->assertOk();
        $path = $this->download();                                                                  // 10-01 Site-1, version 1
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $site2, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);

        $file = $this->edit($path, [$this->code($this->eng) => ['2026-10-01' => 'Site-1']]);
        foreach (['preview', 'confirm'] as $endpoint) {
            $this->upload($endpoint, $file)->assertOk()
                ->assertJsonPath('data.errors.0.error', 'concurrency_conflict')->assertJsonPath('data.errors.0.column', 'H')->assertJsonCount(1, 'data.errors');
        }
        $day = AttendanceDay::where(['employee_id' => $this->eng, 'date' => '2026-10-01'])->sole();
        $this->assertSame([$site2, 2, 'entered'], [$day->site_id, $day->version, $day->source]);
    }

    public function test_the_import_changes_only_the_site_and_keeps_the_rest(): void
    {
        $site2 = $this->workSite('Site-2');
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site, 'late_minutes' => 10, 'note' => 'Note-1'])->assertOk();

        $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => 'Site-2']]);
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.changes.0.from', 'Site-1')->assertJsonPath('data.changes.0.to', 'Site-2');
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.applied', 1);

        $day = AttendanceDay::where(['employee_id' => $this->eng, 'date' => '2026-10-01'])->sole();
        $this->assertSame([$site2, 10, 'Note-1', 2, 'imported'], [$day->site_id, $day->late_minutes, $day->note, $day->version, $day->source]);
    }

    public function test_office_present_word_in_either_language(): void
    {
        [$a, $b, $c] = [$this->employee('office'), $this->employee('office'), $this->employee('office')];

        $file = $this->edit($this->download('office'), [
            $this->code($a) => ['2026-10-01' => 'حاضر'], $this->code($b) => ['2026-10-01' => 'PRESENT'], $this->code($c) => ['2026-10-01' => 'Site-1'],
        ]);
        $this->upload('preview', $file, 'office')->assertOk()->assertJsonPath('data.changes.0.to', 'حاضر')->assertJsonPath('data.changes.1.to', 'حاضر');
        $this->upload('confirm', $file, 'office')->assertOk()->assertJsonPath('data.applied', 2)
            ->assertJsonPath('data.errors', [[
                'row' => 4, 'column' => 'C', 'code' => (int) $this->code($c), 'date' => '2026-10-01', 'error' => 'unknown_site', 'message' => 'الموقع أو نوع اليوم غير موجود.',
            ]]);
        foreach ([$a, $b] as $id) {
            $day = AttendanceDay::where(['employee_id' => $id, 'date' => '2026-10-01'])->sole();
            $this->assertSame([null, null, 'imported'], [$day->site_id, $day->in_time, $day->source]);
        }

        // Ruling (Task 10): a stored present day is prefilled with the present word, and reads back as unchanged.
        $this->upload('preview', $this->file($this->download('office')), 'office')->assertOk()->assertJsonPath('data.summary.changes', 0);
    }

    public function test_a_blank_cell_never_clears_a_stored_day(): void
    {
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site])->assertOk();

        $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => null, '2026-10-02' => null]]);
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary', ['cells' => 3, 'changes' => 0, 'unchanged' => 3, 'errors' => 0]);
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.applied', 0);
        $day = AttendanceDay::where(['employee_id' => $this->eng, 'date' => '2026-10-01'])->sole();
        $this->assertSame([$this->site, 1], [$day->site_id, $day->version]);
    }

    public function test_header_dates_in_any_excel_form_are_read_the_same(): void
    {
        $this->workSite('Site-1 North');
        $second = $this->employee('engineer');
        $path = $this->download();
        $changes = [
            $this->code($this->eng) => ['2026-10-01' => 'Site-1 North', '2026-10-04' => $this->leaveName('sick')],
            $this->code($second) => ['2026-10-01' => 'Site-1'],
        ];
        $expected = $this->upload('preview', $this->edit($path, $changes))->assertOk()->assertJsonPath('data.summary.errors', 0)->json('data.changes');
        $this->assertCount(3, $expected);

        $serial = fn (string $date) => (int) (new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable($date))->days;
        $forms = [
            'date cells' => fn (string $d): Cell => new DateTimeCell(new DateTimeImmutable($d), new Style(format: 'yyyy-mm-dd')),
            'serial numbers' => fn (string $d): Cell => new NumericCell($serial($d)),
            'decimal serials' => fn (string $d): Cell => new NumericCell($serial($d) + 0.25),
        ];
        foreach ($forms as $form => $cell) {
            $edited = $this->edit($path, $changes);
            $file = $this->rewrite($edited->getPathname(), fn (string $sheet, array $rows) => $sheet === 'lists' ? $rows : [
                [...array_slice($rows[0], 0, 2), ...array_map($cell, array_slice($rows[0], 2))], ...array_slice($rows, 1),
            ]);
            $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.changes', $expected, $form);
        }

        // Arabic-Indic and Eastern digits in the codes, and extra spaces in a site name.
        $digits = fn (string $code, string $zero) => implode('', array_map(fn (string $d) => mb_chr(mb_ord($zero) + (int) $d), str_split($code)));
        $file = $this->edit($path, [
            $this->code($this->eng) => ['2026-10-01' => "  Site-1 \u{00A0}  North ", '2026-10-04' => $this->leaveName('sick')],
            $this->code($second) => ['2026-10-01' => ' site-1 '],
        ]);
        $file = $this->rewrite($file->getPathname(), fn (string $sheet, array $rows) => $sheet !== 'attendance' ? $rows : array_map(fn (array $r) => match ((string) $r[0]) {
            $this->code($this->eng) => [$digits($this->code($this->eng), '٠'), ...array_slice($r, 1)],
            $this->code($second) => [$digits($this->code($second), '۰'), ...array_slice($r, 1)],
            default => $r,
        }, $rows));
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.changes', $expected);
    }

    public function test_an_untouched_cell_on_a_deleted_or_inactive_site_is_unchanged(): void
    {
        $deleted = $this->workSite('Site-2');
        $inactive = $this->workSite('Site-3');
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $deleted])->assertOk();
        $this->putDay($this->eng, '2026-10-04', ['site_id' => $inactive])->assertOk();
        Site::findOrFail($deleted)->delete();
        Site::whereKey($inactive)->update(['active' => false]);
        $file = $this->file($this->download());                                                     // still names Site-2 and Site-3

        // 6 cells: the two stored days and the 4 derived days.
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary', ['cells' => 6, 'changes' => 0, 'unchanged' => 6, 'errors' => 0]);
        $this->workSite('Site-2');                                                                  // a new site takes the deleted one's name
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary', ['cells' => 6, 'changes' => 0, 'unchanged' => 6, 'errors' => 0]);
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data', ['applied' => 0, 'unchanged' => 6, 'errors' => []]);
        $day = AttendanceDay::where(['employee_id' => $this->eng, 'date' => '2026-10-01'])->sole();
        $this->assertSame([$deleted, 1], [$day->site_id, $day->version]);
    }

    public function test_a_name_shared_by_two_sites_is_unknown(): void
    {
        Site::create(['name_ar' => 'Site-2', 'name_en' => 'North', 'kind' => 'work']);
        Site::create(['name_ar' => 'Site-3', 'name_en' => 'north', 'kind' => 'work', 'active' => false]);
        $this->workSite('Site-4');
        $this->workSite('site-4');                                                                  // name_ar differing only in case

        $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => 'North', '2026-10-03' => 'Site-4', '2026-10-04' => 'Site-2']]);
        $r = $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary.changes', 1)->assertJsonPath('data.changes.0.to', 'Site-2');
        $this->assertSame([['H', 'unknown_site'], ['J', 'unknown_site']], array_map(fn (array $e) => [$e['column'], $e['error']], $r->json('data.errors')));
    }

    public function test_an_untouched_derived_day_whose_name_another_site_shares_is_unchanged(): void
    {
        Site::create(['name_ar' => 'Site-2', 'name_en' => $this->leaveName('friday'), 'kind' => 'work']);   // the Friday type's name, reused
        $file = $this->file($this->download());                                                     // the derived Fridays say that name

        // The derived Fridays 10-02, 10-09, 10-16 and the holiday 10-06.
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.summary', ['cells' => 4, 'changes' => 0, 'unchanged' => 4, 'errors' => 0]);
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data', ['applied' => 0, 'unchanged' => 4, 'errors' => []]);
        $this->assertSame(0, AttendanceDay::withTrashed()->count());
    }

    public function test_versions_follow_the_code_when_the_visible_rows_are_reordered(): void
    {
        $second = $this->employee('engineer');
        $site2 = $this->workSite('Site-2');
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $this->site])->assertOk();
        $this->putDay($this->eng, '2026-10-01', ['site_id' => $site2, 'version' => 1])->assertOk();  // version 2
        $this->putDay($second, '2026-10-01', ['site_id' => $this->site])->assertOk();              // version 1

        $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => 'Site-1'], $this->code($second) => ['2026-10-01' => 'Site-2']]);
        // HR sorts the visible sheet; the hidden versions sheet stays as downloaded.
        $file = $this->rewrite($file->getPathname(), fn (string $sheet, array $rows) => $sheet === 'attendance' ? [$rows[0], ...array_reverse(array_slice($rows, 1))] : $rows);

        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.applied', 2)->assertJsonPath('data.errors', []);
        $this->assertSame([$this->site, 3], AttendanceDay::where(['employee_id' => $this->eng, 'date' => '2026-10-01'])->get(['site_id', 'version'])->map(fn (AttendanceDay $d) => [$d->site_id, $d->version])->sole());
        $this->assertSame([$site2, 2], AttendanceDay::where(['employee_id' => $second, 'date' => '2026-10-01'])->get(['site_id', 'version'])->map(fn (AttendanceDay $d) => [$d->site_id, $d->version])->sole());
    }

    public function test_an_english_preview_names_employees_and_leave_types_in_english(): void
    {
        $this->employee('engineer', '2026-01-01', ['name_en' => 'Engineer One']);
        $code = Employee::where('name_en', 'Engineer One')->value('code');
        $id = (string) Employee::where('name_en', 'Engineer One')->value('id');
        $this->putDay($id, '2026-10-04', ['site_id' => $this->leave['sick']])->assertOk();

        $file = $this->edit($this->download(), [(string) $code => ['2026-10-03' => $this->leaveName('absent'), '2026-10-04' => 'Site-1']]);
        $r = $this->upload('preview', $file, locale: 'en')->assertOk();

        // The seeded leave types have no name_en; English falls back to the day-type's own English name.
        $this->assertSame([['Engineer One', null, 'Absent'], ['Engineer One', 'Sick leave', 'Site-1']], array_map(fn (array $c) => [$c['name_en'], $c['from'], $c['to']], $r->json('data.changes')));
    }

    public function test_a_template_downloaded_in_english_uploads_under_arabic(): void
    {
        [$a, $b] = [$this->employee('office'), $this->employee('office')];
        $this->putDay($a, '2026-10-01', ['in_time' => '09:00'])->assertOk();
        $path = $this->download('office', locale: 'en');                                            // Employee ID, Name, …; 10-01 prefilled "Present"

        $file = $this->edit($path, [$this->code($b) => ['2026-10-01' => 'Present']]);
        $this->upload('preview', $file, 'office')->assertOk()->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.summary.changes', 1)
            ->assertJsonPath('data.changes.0.employee_id', $b)->assertJsonPath('data.changes.0.to', 'حاضر');
        $this->upload('confirm', $file, 'office')->assertOk()->assertJsonPath('data.applied', 1);
        $day = AttendanceDay::where(['employee_id' => $a, 'date' => '2026-10-01'])->sole();
        $this->assertSame([1, '09:00:00'], [$day->version, $day->in_time]);
    }

    /** @return array<string, array{string, string}> error code => [error, message key] */
    public static function cellErrors(): array
    {
        return [
            'unknown_employee' => ['unknown_employee', 'attendance.import.unknown_employee'],
            'duplicate_row' => ['duplicate_row', 'attendance.import.duplicate_row'],
            'future_date' => ['future_date', 'attendance.refusal.future_date'],
            'employee_not_active' => ['employee_not_active', 'attendance.refusal.employee_not_active'],
            'unknown_site' => ['unknown_site', 'attendance.import.unknown_site'],
            'site_inactive' => ['site_inactive', 'attendance.rule.site_inactive'],
        ];
    }

    #[DataProvider('cellErrors')]
    public function test_each_cell_error_kind(string $error, string $messageKey): void
    {
        $code = $this->code($this->eng);
        $hired = $this->employee('engineer', '2026-10-10');
        $this->workSite('Site-2', active: false);
        [$changes, $append, $at] = match ($error) {
            'unknown_employee' => [[], [['9999', ['2026-10-01' => 'Site-1']]], [4, null, 9999, null]],
            'duplicate_row' => [[], [[$code, ['2026-10-01' => 'Site-1']]], [4, null, (int) $code, null]],
            'future_date' => [[$code => ['2026-10-21' => 'Site-1']], [], [2, $this->column('2026-10-21'), (int) $code, '2026-10-21']],
            'employee_not_active' => [[$this->code($hired) => ['2026-10-05' => 'Site-1']], [], [3, 'L', (int) $this->code($hired), '2026-10-05']],
            'unknown_site' => [[$code => ['2026-10-01' => 'Site-9']], [], [2, 'H', (int) $code, '2026-10-01']],
            'site_inactive' => [[$code => ['2026-10-01' => 'Site-2']], [], [2, 'H', (int) $code, '2026-10-01']],
        };
        $changes[$code]['2026-10-04'] = 'Site-1';                                                  // a valid cell beside the refused one
        $file = $this->edit($this->download(), $changes, $append);

        $expected = [['row' => $at[0], 'column' => $at[1], 'code' => $at[2], 'date' => $at[3], 'error' => $error, 'message' => __($messageKey, [], 'ar')]];
        $this->upload('preview', $file)->assertOk()->assertJsonPath('data.errors', $expected)->assertJsonPath('data.summary.changes', 1);
        $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.errors', $expected)->assertJsonPath('data.applied', 1);
        $this->assertSame([[$this->eng, '2026-10-04']], AttendanceDay::get(['employee_id', 'date'])->map(fn (AttendanceDay $d) => [$d->employee_id, $d->date])->all());
    }

    public function test_a_locked_period_refuses_every_changed_cell(): void
    {
        $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => 'Site-1', '2026-10-03' => 'No-Such-Site']]);
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();
        $locked = AttendanceDay::count();                                                           // the derived days, now stored

        foreach (['preview', 'confirm'] as $endpoint) {
            $r = $this->upload($endpoint, $file)->assertOk();
            $this->assertSame([['H', 'period_locked'], ['J', 'period_locked']], array_map(fn (array $e) => [$e['column'], $e['error']], $r->json('data.errors')), $endpoint);
        }
        $r->assertJsonPath('data.applied', 0)->assertJsonPath('data.unchanged', 4);                // the materialized days still match
        $this->assertSame($locked, AttendanceDay::count());
    }

    public function test_the_file_is_checked_for_size_type_and_template(): void
    {
        Storage::fake('local');

        $this->upload('preview', UploadedFile::fake()->create('attendance.xlsx', 5121))->assertStatus(422)->assertJsonPath('error.details.0.field', 'file');
        $this->upload('preview', UploadedFile::fake()->createWithContent('attendance.csv', "code,name\n1,Engineer-1\n"))
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'file');
        $this->upload('preview', $this->file($this->download('engineer', '2026-08-26')))->assertStatus(422)->assertJsonPath('error.code', 'template_mismatch');
        $this->upload('preview', $this->rewrite($this->download(), fn (string $sheet, array $rows) => $rows, drop: ['versions']))
            ->assertStatus(422)->assertJsonPath('error.code', 'template_mismatch')->assertJsonPath('error.message', 'الملف لا يطابق قالب الحضور لهذه الفترة.');
        $this->upload('preview', $this->rewrite($this->download(), fn (string $sheet, array $rows) => $sheet === 'versions' ? [$rows[0]] : $rows))
            ->assertOk()->assertJsonPath('data.summary.errors', 0);                                 // no stored day: blank versions are right

        $truncated = $this->download();
        $bytes = (string) file_get_contents($truncated);
        file_put_contents($truncated, substr($bytes, 0, intdiv(strlen($bytes), 2)));
        $this->upload('preview', $this->file($truncated))->assertStatus(422)->assertJsonPath('error.code', 'unreadable_file')
            ->assertJsonPath('error.message', 'تعذّرت قراءة الملف.');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_too_many_rows_is_refused(): void
    {
        $this->app->when(AttendanceImport::class)->needs('$maxRows')->give(3);
        foreach (range(1, 3) as $i) {
            $this->employee('engineer');
        }

        $this->upload('preview', $this->file($this->download()))->assertStatus(422)
            ->assertJsonPath('error.code', 'too_many_rows')->assertJsonPath('error.message', 'يحتوي الملف على أكثر من 3 صف.');
        $this->assertSame(2000, AttendanceImport::MAX_ROWS);
    }

    public function test_import_follows_own_scope(): void
    {
        $this->assign($this->eng, $this->makeUser('manager'));
        // The narrower of view and edit wins (P34): each lead imports only their own employee from HR's file.
        foreach ([['own', 'own'], ['all', 'own'], ['own', 'all']] as [$view, $edit]) {
            $grants = "view.{$view} + edit.{$edit}";
            $lead = $this->userWith($view, $edit);
            $mine = $this->assign($this->employee('engineer'), $lead);
            $file = $this->edit($this->download(), [$this->code($this->eng) => ['2026-10-01' => 'Site-1'], $this->code($mine) => ['2026-10-01' => 'Site-1']]);

            $this->actingAs($lead, 'api');
            $errors = $this->upload('confirm', $file)->assertOk()->assertJsonPath('data.applied', 1)->json('data.errors');
            $this->assertContains(['row' => 2, 'column' => null, 'code' => (int) $this->code($this->eng), 'date' => null, 'error' => 'unknown_employee', 'message' => 'الرقم الوظيفي غير موجود.'], $errors, $grants);
            $this->assertSame(['unknown_employee'], array_values(array_unique(array_column($errors, 'error'))), $grants);   // earlier leads' rows too
            $this->assertTrue(AttendanceDay::where(['employee_id' => $mine, 'date' => '2026-10-01'])->exists(), $grants);
        }
        $this->assertFalse(AttendanceDay::where('employee_id', $this->eng)->exists());

        foreach ([$this->userWith('all', null), $this->userWith(null, 'all')] as $user) {             // view or edit missing
            $this->actingAs($user, 'api');
            $this->upload('preview', $file)->assertForbidden();
            $this->upload('confirm', $file)->assertForbidden();
        }
    }

    /** The template of the group's period (default: its period containing 2026-10-20), downloaded in $locale and saved to a temp file. */
    private function download(string $group = 'engineer', ?string $start = null, string $locale = 'ar'): string
    {
        $start ??= self::STARTS[$group];
        $response = $this->get("/api/v1/attendance/template?group={$group}&period_start={$start}", ['Accept-Language' => $locale])->assertOk();
        $sent = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $sent);
        ob_start();
        $sent->sendContent();                                                                       // also deletes the server's temp file
        $path = $this->temp();
        file_put_contents($path, (string) ob_get_clean());

        return $path;
    }

    /**
     * Sets attendance cells by employee code and date (null blanks a cell), then appends rows [code, [date => value]].
     *
     * @param  array<string, array<string, ?string>>  $changes
     * @param  list<array{string, array<string, string>}>  $append
     */
    private function edit(string $path, array $changes, array $append = []): UploadedFile
    {
        return $this->rewrite($path, function (string $sheet, array $rows) use ($changes, $append): array {
            if ($sheet !== 'attendance') {
                return $rows;
            }
            $columns = array_flip(array_slice($rows[0], 2));
            foreach ($rows as $i => $row) {
                foreach ($changes[(string) $row[0]] ?? [] as $date => $value) {
                    $rows[$i][2 + $columns[$date]] = $value;
                }
            }
            foreach ($append as [$code, $cells]) {
                $row = array_fill(0, count($rows[0]), null);
                $row[0] = $code;
                foreach ($cells as $date => $value) {
                    $row[2 + $columns[$date]] = $value;
                }
                $rows[] = $row;
            }

            return $rows;
        });
    }

    /**
     * Rewrites every sheet with OpenSpout's writer, keeping the sheet order and the hidden sheets; $map(sheet name, rows) returns the new rows.
     *
     * @param  callable(string, list<list<mixed>>): list<list<mixed>>  $map
     * @param  list<string>  $drop  sheets left out
     */
    private function rewrite(string $path, callable $map, array $drop = []): UploadedFile
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[] = [$sheet->getName(), $sheet->isVisible(), $rows];
        }
        $reader->close();

        $out = $this->temp();
        $writer = new Writer;
        $writer->openToFile($out);
        $first = true;
        foreach ($sheets as [$name, $visible, $rows]) {
            if (in_array($name, $drop, true)) {
                continue;
            }
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            $sheet->setName($name)->setIsVisible($visible);
            foreach ($map($name, $rows) as $row) {
                $writer->addRow(new Row(array_map(fn (mixed $v): Cell => match (true) {
                    $v instanceof Cell => $v,
                    $v === null || $v === '' => new EmptyCell(null),
                    is_int($v) || is_float($v) => new NumericCell($v),
                    default => new StringCell((string) $v),
                }, array_values($row))));
            }
        }
        $writer->close();

        return $this->file($out);
    }

    private function file(string $path): UploadedFile
    {
        return new UploadedFile($path, 'attendance.xlsx', null, null, true);
    }

    private function upload(string $endpoint, UploadedFile $file, string $group = 'engineer', string $locale = 'ar'): TestResponse
    {
        return $this->post("/api/v1/attendance/import/{$endpoint}", ['group' => $group, 'period_start' => self::STARTS[$group], 'file' => $file], [
            'Accept' => 'application/json', 'Accept-Language' => $locale,
        ]);
    }

    private function temp(): string
    {
        return $this->temps[] = (string) tempnam(sys_get_temp_dir(), 'import-test-');
    }

    private function code(string $employeeId): string
    {
        return (string) Employee::whereKey($employeeId)->value('code');
    }

    private function leaveName(string $code): string
    {
        return (string) Site::whereKey($this->leave[$code])->value('name_ar');
    }

    /** The column letter of an engineer period date (09-26 is column C). */
    private function column(string $date): string
    {
        $i = 2 + (int) (new DateTimeImmutable('2026-09-26'))->diff(new DateTimeImmutable($date))->days;

        return ($i >= 26 ? chr(64 + intdiv($i, 26)) : '').chr(65 + $i % 26);
    }

    /** Assigns the employee to the manager from 10-01, as HR; returns the employee id. */
    private function assign(string $employeeId, User $manager): string
    {
        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/employees/{$employeeId}/manager-assignments", ['manager_id' => $manager->id, 'effective_from' => '2026-10-01'])->assertCreated();

        return $employeeId;
    }
}
