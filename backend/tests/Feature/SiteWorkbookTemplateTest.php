<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\SiteWorkbookTemplate;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;
use Tests\XlsxFiles;

/** The site workbook (calculations §14 W1, W4; spec docs/superpowers/specs/2026-11-12-site-workbook-design.md «The file»). */
class SiteWorkbookTemplateTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;
    use XlsxFiles;

    private const COLUMNS = ['الاسم', 'الحالة', 'حضور', 'انصراف', 'سلفة', 'ملاحظات', 'الرقم القومي', 'الموبايل', 'محل الإقامة', 'الأجر المقترح'];

    private const WEEK = ['السبت 07-11', 'الأحد 08-11', 'الاثنين 09-11', 'الثلاثاء 10-11', 'الأربعاء 11-11', 'الخميس 12-11', 'الجمعة 13-11'];

    private const PROMPT = 'اختار الاسم من القايمة، أو اكتب اسم عامل يومية جديد وبياناته';

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

    /** W4, spec «The name list»: the engineers assigned to any of the site's managers are own people, not only the first manager's. */
    public function test_the_engineers_of_every_manager_of_the_site_are_own_people(): void
    {
        $second = $this->managerOf($this->site);
        $first = $this->assign($this->employee('engineer', '2026-01-01', ['name_ar' => 'Eng-A']), $this->manager);
        $other = $this->assign($this->employee('engineer', '2026-01-01', ['name_ar' => 'Eng-B']), $second);

        $names = $this->names();

        $this->assertSame([$this->entry($first, 'مهندس'), $this->entry($other, 'مهندس')], array_column($names, 'entry'));
        $this->assertSame([true, true], array_column($names, 'own'));
    }

    /** W4: office staff homed here but employed on no day of the range (terminated before it starts, not yet archived) are left out. */
    public function test_someone_employed_on_no_day_of_the_range_is_left_out(): void
    {
        $office = $this->employee('office', '2026-01-01', ['name_ar' => 'Leaving', 'termination_date' => '2026-11-20']);
        $this->homeSite($office, $this->site, '2026-01-01');

        // The roster still counts them as the site's own on the range's first day (officeIdsAt reads the type, not the employment).
        $this->assertSame([$office], array_column(app(EmployeeRoster::class)->siteWorkbookPeople($this->site, [], '2026-11-21', '2026-11-27')['own'], 'id'));
        $this->assertSame([], app(SiteWorkbookTemplate::class)->names($this->site, '2026-11-21', '2026-11-27'));
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

    /** W1, spec «The file»: one right-to-left day sheet per date in date order, the first selected, then the hidden `lists`; no `versions`. */
    public function test_one_right_to_left_sheet_per_date_then_the_hidden_lists(): void
    {
        $sheets = $this->download($this->params());

        $this->assertSame([...self::WEEK, 'lists'], array_keys($sheets));
        $this->assertSame([...array_fill(0, 7, true), false], array_column($sheets, 'visible'));
        foreach (self::WEEK as $i => $day) {
            $sheet = simplexml_load_string($this->zipEntry('xl/worksheets/sheet'.($i + 1).'.xml'));
            $view = $sheet->sheetViews->sheetView;
            $this->assertContains((string) $view['rightToLeft'], ['1', 'true'], $day);
            $this->assertSame(['1', '2', 'B3', 'frozen'], [(string) $view->pane['xSplit'], (string) $view->pane['ySplit'], (string) $view->pane['topLeftCell'], (string) $view->pane['state']], $day);
            $this->assertSame($i === 0, in_array((string) $view['tabSelected'], ['1', 'true'], true), $day);
            $this->assertSame(['A1:J1'], array_map(fn ($m) => (string) $m['ref'], iterator_to_array($sheet->mergeCells->mergeCell, false)), $day);

            $cells = $this->cells($i + 1);
            $this->assertSame(['value' => "موقع Site-S — {$day}-2026", 'type' => 'inlineStr', 'fill' => 'DDEBF7', 'text' => true, 'bold' => true, 'size' => '14'], $cells['A1'], $day);
            $this->assertSame(self::COLUMNS, array_map(fn (string $k) => $cells["{$k}2"]['value'], str_split('ABCDEFGHIJ')), $day);
            $this->assertSame(array_fill(0, 10, true), array_map(fn (string $k) => $cells["{$k}2"]['bold'], str_split('ABCDEFGHIJ')), $day);
        }

        // A range across a month end (an engineer's 26th → 25th period) names each sheet by its own date.
        $this->assertSame(
            ['الخميس 26-11', 'الجمعة 27-11', 'السبت 28-11', 'الأحد 29-11', 'الاثنين 30-11', 'الثلاثاء 01-12', 'الأربعاء 02-12', 'lists'],
            array_keys($this->download($this->params('2026-11-26', '2026-12-02'))),
        );
    }

    /** Spec «The file»: A picks or types the name (no error alert, a prompt); B and I are blocking lists; rows 3 to the last on every day sheet. */
    public function test_every_day_sheet_has_the_three_dropdowns(): void
    {
        $this->homeSite($this->employee('day_laborer'), $this->site, '2026-01-01');
        $this->employee('engineer');
        Residence::create(['name_ar' => 'Residence-1']);
        Residence::create(['name_ar' => 'Residence-2']);
        $statuses = 1 + Site::where(['kind' => 'leave', 'active' => true])->count();

        $this->download($this->params());

        foreach (self::WEEK as $i => $day) {
            $this->assertSame([
                'A3:A32' => ['list', 'lists!$B$1:$B$2', '0', 'إضافة', self::PROMPT],
                'B3:B32' => ['list', 'lists!$A$1:$A$'.$statuses, '1', '', ''],
                'I3:I32' => ['list', 'lists!$C$1:$C$2', '1', '', ''],
            ], $this->validations($i + 1), $day);
        }
    }

    /** Spec «The file»: 30 empty rows, or twice the site's own people; every cell text; A yellow, G–J tinted; widths fit header and list. */
    public function test_rows_are_empty_text_cells_with_the_fills(): void
    {
        foreach (range(1, 15) as $n) {
            $this->homeSite($this->employee('day_laborer'), $this->site, '2026-01-01');
        }
        $this->employee('engineer', '2026-01-01', ['name_ar' => 'An engineer of no site, with the longest name of all']);
        Residence::create(['name_ar' => 'Residence-1']);

        $this->download($this->params());
        $this->assertRows(30);

        $this->homeSite($this->employee('day_laborer'), $this->site, '2026-01-01');                 // 16 own people
        $this->download($this->params());
        $this->assertRows(32);

        $statuses = ['حاضر', ...Site::where(['kind' => 'leave', 'active' => true])->pluck('name_ar')->all()];
        $fit = fn (array $values) => (float) (max(8, ...array_map(mb_strlen(...), $values)) + 3);
        $expected = array_map(fn (string $header) => $fit([$header]), self::COLUMNS);
        $expected[0] = $fit([self::COLUMNS[0], ...array_column($this->names(), 'entry')]);
        $expected[1] = $fit([self::COLUMNS[1], ...$statuses]);
        $expected[8] = $fit([self::COLUMNS[8], 'Residence-1']);
        foreach (self::WEEK as $i => $day) {
            $this->assertSame(array_combine(range(1, 10), $expected), $this->columnWidths($i + 1), $day);
        }
    }

    /** Spec «The file»: `lists` A the statuses, B the name list, C the residences, D the site and range; a name is never a formula. */
    public function test_lists_holds_statuses_names_residences_and_the_site_and_range(): void
    {
        $leave = $this->leaveTypes();
        Site::whereKey($leave['rest'])->update(['active' => false]);
        Site::findOrFail($leave['other'])->delete();
        Site::whereKey($leave['absent'])->update(['name_ar' => 'غ']);
        $this->homeSite($this->employee('day_laborer', '2026-01-01', ['name_ar' => '=SUM(1)']), $this->site, '2026-01-01');
        $this->employee('engineer');
        Residence::create(['name_ar' => 'Residence-B']);
        Residence::create(['name_ar' => 'Residence-A']);
        Residence::create(['name_ar' => 'Residence-C'])->delete();

        $this->download($this->params());

        $lists = $this->cells(8);
        // As the approved sample: the present word, then absence (غ), then the other active leave day-types (the migrations' four and
        // leaveTypes()' own, less rest and other) by name in code-point order.
        $this->assertSame(
            ['حاضر', 'غ', 'Leave-friday', 'Leave-official_holiday', 'Leave-sick', 'اجازة السبت', 'اعتيادية', 'بدون أجر', 'عارضة'],
            $this->column($lists, 'A'),
        );
        $this->assertSame(array_column($this->names(), 'entry'), $this->column($lists, 'B'));
        $this->assertStringStartsWith('=SUM(1) (يومية) · ', $lists['B1']['value']);
        $this->assertSame('inlineStr', $lists['B1']['type']);
        $this->assertStringNotContainsString('<f>', $this->zipEntry('xl/worksheets/sheet8.xml'), 'a name is never written as a formula');
        $this->assertSame(['Residence-A', 'Residence-B'], $this->column($lists, 'C'));
        $this->assertSame([$this->site, '2026-11-07', '2026-11-13'], $this->column($lists, 'D'));
    }

    /** W1: the file is Arabic whatever the request language. */
    public function test_the_file_is_arabic_with_an_english_request(): void
    {
        $this->homeSite($this->employee('day_laborer'), $this->site, '2026-01-01');

        $sheets = $this->download($this->params(), ['Accept-Language' => 'en']);

        $this->assertSame([...self::WEEK, 'lists'], array_keys($sheets));
        $cells = $this->cells(1);
        $this->assertSame('موقع Site-S — السبت 07-11-2026', $cells['A1']['value']);
        $this->assertSame(self::COLUMNS, array_map(fn (string $k) => $cells["{$k}2"]['value'], str_split('ABCDEFGHIJ')));
        $this->assertContains((string) simplexml_load_string($this->zipEntry('xl/worksheets/sheet1.xml'))->sheetViews->sheetView['rightToLeft'], ['1', 'true']);
        $this->assertSame(['list', 'lists!$B$1:$B$1', '0', 'إضافة', self::PROMPT], $this->validations(1)['A3:A32']);
        $lists = $this->cells(8);
        $this->assertSame('حاضر', $lists['A1']['value']);
        $this->assertStringContainsString(' (يومية) · ', (string) $lists['B1']['value']);
    }

    /** Review Focus 5: an empty list gets no dropdown rather than an invalid range (`$B$1:$B$0`). */
    public function test_an_empty_name_or_residence_list_adds_no_dropdown_for_it(): void
    {
        $this->download($this->params());

        $statuses = 1 + Site::where(['kind' => 'leave', 'active' => true])->count();
        foreach (self::WEEK as $i => $day) {
            $this->assertSame(['B3:B32' => ['list', 'lists!$A$1:$A$'.$statuses, '1', '', '']], $this->validations($i + 1), $day);
            $this->assertSame(11.0, $this->columnWidths($i + 1)[1], $day);                          // max(8, «الاسم») + 3
        }
        $lists = $this->cells(8);
        $this->assertSame([], $this->column($lists, 'B'));
        $this->assertSame([], $this->column($lists, 'C'));
        $this->assertSame([$this->site, '2026-11-07', '2026-11-13'], $this->column($lists, 'D'));
    }

    /** @return array<string, array{string, string}> */
    public static function badRequests(): array
    {
        return [
            'an inactive site' => ['inactive', 'site_id'],
            'a leave day-type' => ['leave', 'site_id'],
            'a deleted site' => ['deleted', 'site_id'],
            'not a uuid' => ['not_uuid', 'site_id'],
            'no site' => ['no_site', 'site_id'],
            'a bad date' => ['bad_date', 'from'],
            'from after to' => ['reversed', 'to'],
            '32 days' => ['32_days', 'to'],
            // Security review A1: a range ending 9999-12-31 ran its dates on past the year 9999 until memory ran out.
            'a range in the year 9999' => ['9999', 'to'],
            'a range starting before 2000' => ['before_2000', 'to'],
        ];
    }

    /** W1, spec «Endpoints and permissions»: a live, active work site and at most 31 days within the years 2000–2100; anything else is 422. */
    #[DataProvider('badRequests')]
    public function test_the_download_refuses_a_bad_site_or_range(string $case, string $field): void
    {
        $query = match ($case) {
            'inactive' => $this->params(site: $this->workSite('Site-off', active: false)),
            'leave' => $this->params(site: $this->leaveTypes()['sick']),
            'deleted' => $this->params(site: $this->deletedSite()),
            'not_uuid' => $this->params(site: 'Site-S'),
            'no_site' => 'from=2026-11-07&to=2026-11-13',
            'bad_date' => $this->params('2026-02-30', '2026-03-02'),
            'reversed' => $this->params('2026-11-13', '2026-11-07'),
            '32_days' => $this->params('2026-11-01', '2026-12-02'),
            '9999' => $this->params('9999-12-01', '9999-12-31'),
            'before_2000' => $this->params('1999-12-31', '2000-01-02'),
        };

        $response = $this->getJson('/api/v1/attendance/site-workbook?'.$query, ['Accept-Language' => 'en'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.field', $field);
        if (in_array($case, ['32_days', '9999', 'before_2000'], true)) {
            $response->assertJsonPath('error.details.0.message', 'The range is at most 31 days, within the years 2000 to 2100.');
        }
    }

    /** W1: 31 days is the longest range; future dates are allowed. */
    public function test_31_days_is_the_longest_range(): void
    {
        $sheets = $this->download($this->params('2026-11-01', '2026-12-01'));

        $this->assertCount(32, $sheets);
        $this->assertSame(['الأحد 01-11', 'الثلاثاء 01-12', 'lists'], [array_key_first($sheets), ...array_slice(array_keys($sheets), -2)]);
    }

    /** Spec «Security notes»: the file writes as HR (no owner), so own scope on either permission is 403 before anything is read. */
    public function test_an_own_scope_caller_gets_403(): void
    {
        foreach ([['own', 'own'], ['all', 'own'], ['own', 'all']] as [$view, $edit]) {
            $this->actingAs($this->userWith($view, $edit), 'api');
            $this->getJson('/api/v1/attendance/site-workbook?'.$this->params())->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
            $this->getJson('/api/v1/attendance/site-workbook')->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');   // before validation
        }
        $this->actingAs($this->manager, 'api');
        $this->getJson('/api/v1/attendance/site-workbook?'.$this->params())->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
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

    private function params(string $from = '2026-11-07', string $to = '2026-11-13', ?string $site = null): string
    {
        return http_build_query(['site_id' => $site ?? $this->site, 'from' => $from, 'to' => $to]);
    }

    private function deletedSite(): string
    {
        $id = $this->workSite('Site-gone');
        Site::findOrFail($id)->delete();

        return $id;
    }

    /** Every day sheet: the title and header rows, then $n empty data rows (A an empty text cell); every cell text, A yellow, G–J tinted. */
    private function assertRows(int $n): void
    {
        foreach (self::WEEK as $i => $day) {
            $this->assertCount(2 + $n, simplexml_load_string($this->zipEntry('xl/worksheets/sheet'.($i + 1).'.xml'))->sheetData->row, $day);
            $cells = $this->cells($i + 1);
            $this->assertCount(10 * (2 + $n), $cells, $day);
            $this->assertSame([], array_keys(array_filter($cells, fn (array $c) => ! $c['text'])), "{$day}: every cell is text (@)");
            foreach (range(3, 2 + $n) as $r) {
                $this->assertSame(['', 'inlineStr', 'FFF2CC'], [$cells["A{$r}"]['value'], $cells["A{$r}"]['type'], $cells["A{$r}"]['fill']], "{$day} A{$r}");
                foreach (str_split('BCDEFGHIJ') as $k) {
                    $this->assertSame([null, $k >= 'G' ? 'FFF9E5' : null], [$cells["{$k}{$r}"]['value'], $cells["{$k}{$r}"]['fill']], "{$day} {$k}{$r}");
                }
            }
        }
    }

    /**
     * Downloads the workbook, in Arabic unless $headers say otherwise (sending it deletes the temp file), and reads every sheet back.
     *
     * @param  array<string, string>  $headers
     * @return array<string, array{visible: bool, rows: list<list<mixed>>}>
     */
    private function download(string $query, array $headers = []): array
    {
        // Symfony's test requests default to Accept-Language: en-us; the app's own default is Arabic.
        $response = $this->get('/api/v1/attendance/site-workbook?'.$query, [...['Accept-Language' => 'ar'], ...$headers])->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        parse_str($query, $q);
        $this->assertStringContainsString("site-workbook-{$q['from']}-{$q['to']}.xlsx", (string) $response->headers->get('Content-Disposition'));
        $this->xlsx = $this->sent($response);

        return $this->sheets();
    }

    /**
     * A sheet's written cells, as verify.py reads them: value, type, fill colour, text format (@, id 49), bold and size.
     *
     * @return array<string, array{value: ?string, type: ?string, fill: ?string, text: bool, bold: bool, size: string}>
     */
    private function cells(int $sheet): array
    {
        $styles = simplexml_load_string($this->zipEntry('xl/styles.xml'));
        $fills = [];
        foreach ($styles->fills->fill as $fill) {
            $rgb = (string) $fill->patternFill->fgColor['rgb'];
            $fills[] = $rgb === '' ? null : substr($rgb, -6);
        }
        $fonts = [];
        foreach ($styles->fonts->font as $font) {
            $fonts[] = ['bold' => isset($font->b), 'size' => (string) $font->sz['val']];
        }
        $xfs = [];
        foreach ($styles->cellXfs->xf as $xf) {
            $xfs[] = ['fill' => $fills[(int) $xf['fillId']], 'text' => (int) $xf['numFmtId'] === 49, ...$fonts[(int) $xf['fontId']]];
        }

        $cells = [];
        foreach (simplexml_load_string($this->zipEntry("xl/worksheets/sheet{$sheet}.xml"))->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $value = match (true) {
                    isset($c->is) => (string) $c->is->t,
                    isset($c->v) => (string) $c->v,
                    default => null,
                };
                $cells[(string) $c['r']] = ['value' => $value, 'type' => isset($c['t']) ? (string) $c['t'] : null, ...$xfs[(int) $c['s']]];
            }
        }

        return $cells;
    }

    /**
     * @param  array<string, array{value: ?string}>  $cells
     * @return list<?string> column $k's values from row 1 down to its first unwritten cell
     */
    private function column(array $cells, string $k): array
    {
        $values = [];
        for ($r = 1; isset($cells["{$k}{$r}"]); $r++) {
            $values[] = $cells["{$k}{$r}"]['value'];
        }

        return $values;
    }

    /** @return array<string, list<string>> sqref → type, formula, showErrorMessage, promptTitle, prompt */
    private function validations(int $sheet): array
    {
        $rules = [];
        foreach (simplexml_load_string($this->zipEntry("xl/worksheets/sheet{$sheet}.xml"))->dataValidations->dataValidation ?? [] as $v) {
            $rules[(string) $v['sqref']] = [(string) $v['type'], (string) $v->formula1, (string) $v['showErrorMessage'], (string) $v['promptTitle'], (string) $v['prompt']];
        }
        ksort($rules);

        return $rules;
    }

    /** @return array<int, float> column index (A = 1) → custom width, from `<cols>` */
    private function columnWidths(int $sheet): array
    {
        $widths = [];
        foreach (simplexml_load_string($this->zipEntry("xl/worksheets/sheet{$sheet}.xml"))->cols->col ?? [] as $col) {
            if (in_array((string) $col['customWidth'], ['1', 'true'], true)) {
                foreach (range((int) $col['min'], (int) $col['max']) as $i) {
                    $widths[$i] = (float) $col['width'];
                }
            }
        }
        ksort($widths);

        return $widths;
    }
}
