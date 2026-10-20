<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;
use ZipArchive;

class AttendanceTemplateTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const ENGINEERS = 'group=engineer&period_start=2026-09-26';

    /** @var array<string, string> */
    private array $leave;

    private string $site;

    /** The downloaded file's bytes, kept for the XML checks. */
    private string $xlsx = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        $this->site = $this->workSite('Site-1');
    }

    public function test_template_is_a_grid_of_employees_by_period_days_prefilled(): void
    {
        [$one, $two] = $this->twoEngineers();

        $grid = $this->download(self::ENGINEERS)['attendance']['rows'];
        $dates = array_map(fn (int $i) => date('Y-m-d', strtotime("2026-09-26 +{$i} days")), range(0, 29));
        $this->assertSame(['الرقم الوظيفي', 'الاسم', ...$dates], $grid[0]);
        $this->assertCount(3, $grid);

        $cell = fn (int $row, string $date) => $grid[$row][2 + array_search($date, $dates, true)] ?? '';
        $this->assertSame([$this->code($one), 'Engineer-1'], array_slice($grid[1], 0, 2));
        $this->assertSame('Site-1', $cell(1, '2026-10-01'));
        $this->assertSame((string) Site::whereKey($this->leave['friday'])->value('name_ar'), $cell(1, '2026-10-02'));
        $this->assertSame((string) Site::whereKey($this->leave['official_holiday'])->value('name_ar'), $cell(1, '2026-10-06'));
        $this->assertSame('', $cell(1, '2026-10-03'));
        $this->assertSame([$this->code($two), 'Engineer-2'], array_slice($grid[2], 0, 2));
        foreach ([1, 2] as $row) {
            foreach ($dates as $date) {
                if ($date > '2026-10-20' || ($row === 2 && $date < '2026-10-10')) {
                    $this->assertSame('', $cell($row, $date), "row {$row} {$date}");
                }
            }
        }

        // C..AF are 09-26..10-25: Engineer-2 (row 3) cannot take 09-26..10-09 (C..P); nobody can take 10-21..10-25 (AB..AF).
        $future = ['AB', 'AC', 'AD', 'AE', 'AF'];
        $expected = [...array_map(fn (string $c) => "{$c}2", $future), ...array_map(fn (string $c) => "{$c}3", [...range('C', 'P'), ...$future])];
        $this->assertEqualsCanonicalizing($expected, $this->greyCells());
    }

    public function test_template_cells_are_text_with_a_dropdown_frozen_and_right_to_left_in_arabic(): void
    {
        $this->twoEngineers();
        $this->workSite('=1+2');
        $lists = $this->download(self::ENGINEERS)['lists']['rows'];
        $choices = count($lists);
        $this->assertContains(['=1+2'], $lists);
        $this->assertStringNotContainsString('<f>', $this->zipEntry('xl/worksheets/sheet2.xml'), 'a name is never written as a formula');

        $sheet = simplexml_load_string($this->zipEntry('xl/worksheets/sheet1.xml'));
        $view = $sheet->sheetViews->sheetView;
        $this->assertContains((string) $view['rightToLeft'], ['1', 'true']);
        $this->assertSame(['2', '1', 'frozen'], [(string) $view->pane['xSplit'], (string) $view->pane['ySplit'], (string) $view->pane['state']]);
        $rule = $sheet->dataValidations->dataValidation;
        $this->assertSame(['list', 'C2:AF3'], [(string) $rule['type'], (string) $rule['sqref']]);
        $this->assertSame('lists!$A$1:$A$'.$choices, (string) $rule->formula1);   // OpenSpout's CellReference has no whole-column form
        $text = $this->textStyles();
        $this->assertSame([], array_keys(array_filter($this->cellStyles(), fn (int $s) => ! in_array($s, $text, true))), 'every attendance cell is text (@)');
    }

    public function test_template_hides_lists_and_versions(): void
    {
        [$one, $two] = $this->twoEngineers();
        $this->workSite('Site-2', active: false);

        $sheets = $this->download(self::ENGINEERS);
        $this->assertSame(['attendance', 'lists', 'versions'], array_keys($sheets));
        $this->assertTrue($sheets['attendance']['visible']);
        $this->assertFalse($sheets['lists']['visible']);
        $this->assertFalse($sheets['versions']['visible']);

        $versions = $sheets['versions']['rows'];
        $this->assertSame($sheets['attendance']['rows'][0], $versions[0]);
        $this->assertCount(3, $versions);
        $this->assertSame([$this->code($one), 'Engineer-1'], array_slice($versions[1], 0, 2));
        $this->assertSame([$this->code($two), 'Engineer-2'], array_slice($versions[2], 0, 2));
        $this->assertSame([7 => 1], array_filter(array_slice($versions[1], 2, null, true), fn ($v) => $v !== '' && $v !== null));   // 10-01
        $this->assertSame([], array_filter(array_slice($versions[2], 2), fn ($v) => $v !== '' && $v !== null));

        $lists = array_column($sheets['lists']['rows'], 0);
        $active = Site::where(['kind' => 'leave', 'active' => true])->pluck('name_ar')->all();
        $this->assertSame('Site-1', $lists[0]);
        $this->assertEqualsCanonicalizing($active, array_slice($lists, 1));
    }

    public function test_office_template_offers_present_and_leave_types(): void
    {
        $office = $this->employee('office');
        $this->putDay($office, '2026-10-01', ['in_time' => '09:00'])->assertOk();
        $this->putDay($office, '2026-10-04', ['site_id' => $this->leave['sick']])->assertOk();

        $sheets = $this->download('group=office&period_start=2026-10-01');
        $lists = array_column($sheets['lists']['rows'], 0);
        $this->assertSame('حاضر', $lists[0]);
        $this->assertNotContains('Site-1', $lists);
        $grid = $sheets['attendance']['rows'];
        $this->assertCount(2, $grid);
        $this->assertSame('2026-10-31', end($grid[0]));
        $name = fn (string $code) => (string) Site::whereKey($this->leave[$code])->value('name_ar');
        // 10-01 stored present (no site), 10-02 and 10-03 the derived Friday and Saturday, 10-04 stored sick
        $this->assertSame(['حاضر', $name('friday'), $name('saturday'), $name('sick')], array_slice($grid[1], 2, 4));
    }

    public function test_template_headers_follow_the_request_language(): void
    {
        $this->employee('office');

        $sheets = $this->download(self::ENGINEERS, ['Accept-Language' => 'en']);
        $this->assertSame(['Employee ID', 'Name', '2026-09-26'], array_slice($sheets['attendance']['rows'][0], 0, 3));
        $this->assertNotContains((string) simplexml_load_string($this->zipEntry('xl/worksheets/sheet1.xml'))->sheetViews->sheetView['rightToLeft'], ['1', 'true']);

        $this->download('group=office&period_start=2026-10-01', ['Accept-Language' => 'en']);
        $this->assertSame('Present', $this->zipRows('lists')[0][0]);
    }

    public function test_template_needs_attendance_edit(): void
    {
        $this->signIn('ceo');
        $this->get('/api/v1/attendance/template?'.self::ENGINEERS)->assertForbidden();
    }

    public function test_template_validates_the_period(): void
    {
        $this->getJson('/api/v1/attendance/template?group=engineer&period_start=2026-10-01')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->getJson('/api/v1/attendance/template?group=engineer')->assertStatus(422);
        $this->getJson('/api/v1/attendance/template?group=nobody&period_start=2026-09-26')->assertStatus(422);
    }

    public function test_template_follows_own_scope(): void
    {
        $role = Role::create(['slug' => 'site-lead', 'name' => 'Site lead', 'name_ar' => 'مشرف']);
        foreach (['view', 'edit'] as $action) {
            $role->permissions()->attach(Permission::create(['resource' => 'attendance', 'action' => $action, 'scope' => 'own'])->getKey());
        }
        [$lead, $other] = [$this->makeUser('site-lead'), $this->makeUser('manager')];
        [$mine, $theirs] = [$this->employee('engineer'), $this->employee('engineer')];
        $this->postJson("/api/v1/employees/{$mine}/manager-assignments", ['manager_id' => $lead->id, 'effective_from' => '2026-10-01'])->assertCreated();
        $this->postJson("/api/v1/employees/{$theirs}/manager-assignments", ['manager_id' => $other->id, 'effective_from' => '2026-10-01'])->assertCreated();

        $this->assertCount(3, $this->download(self::ENGINEERS)['attendance']['rows']);       // HR: both

        $this->actingAs($lead, 'api');
        $sheets = $this->download(self::ENGINEERS);
        $this->assertCount(2, $sheets['attendance']['rows']);
        $this->assertSame($this->code($mine), $sheets['attendance']['rows'][1][0]);
        $this->assertCount(2, $sheets['versions']['rows']);
    }

    /** @return array{string, string} Engineer-1 (stored 10-01 at Site-1) and Engineer-2 (hired 10-10); 10-06 a holiday */
    private function twoEngineers(): array
    {
        $one = $this->employee('engineer');
        $two = $this->employee('engineer', '2026-10-10');
        $this->holiday('2026-10-06');
        $this->putDay($one, '2026-10-01', ['site_id' => $this->site])->assertOk()->assertJsonPath('data.version', 1);

        return [$one, $two];
    }

    private function code(string $employeeId): string
    {
        return (string) Employee::whereKey($employeeId)->value('code');
    }

    /**
     * Downloads the template, in Arabic unless $headers say otherwise (sending it deletes the temp file), and reads every sheet back.
     *
     * @param  array<string, string>  $headers
     * @return array<string, array{visible: bool, rows: list<list<mixed>>}>
     */
    private function download(string $query, array $headers = []): array
    {
        // Symfony's test requests default to Accept-Language: en-us; the app's own default is Arabic.
        $response = $this->get('/api/v1/attendance/template?'.$query, [...['Accept-Language' => 'ar'], ...$headers])->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        parse_str($query, $q);
        $this->assertStringContainsString("attendance-{$q['group']}-{$q['period_start']}.xlsx", (string) $response->headers->get('Content-Disposition'));
        $this->xlsx = $this->sent($response);

        $path = $this->tempXlsx();
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = ['visible' => $sheet->isVisible(), 'rows' => $rows];
        }
        $reader->close();
        unlink($path);

        return $sheets;
    }

    private function sent(TestResponse $response): string
    {
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $path = $file->getFile()->getPathname();
        ob_start();
        $file->sendContent();
        $bytes = (string) ob_get_clean();
        $this->assertFileDoesNotExist($path);

        return $bytes;
    }

    private function tempXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'template-test-').'.xlsx';
        file_put_contents($path, $this->xlsx);

        return $path;
    }

    private function zipEntry(string $name): string
    {
        $path = $this->tempXlsx();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName($name);
        $zip->close();
        unlink($path);

        return $xml;
    }

    /** @return list<list<mixed>> */
    private function zipRows(string $sheetName): array
    {
        $path = $this->tempXlsx();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === $sheetName) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    /** @return array<string, int> attendance cell ref → style index */
    private function cellStyles(): array
    {
        $sheet = simplexml_load_string($this->zipEntry('xl/worksheets/sheet1.xml'));
        $styles = [];
        foreach ($sheet->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $styles[(string) $c['r']] = (int) $c['s'];
            }
        }

        return $styles;
    }

    /** @return list<int> style indexes whose number format is text (@, built-in id 49) */
    private function textStyles(): array
    {
        $text = [];
        foreach (simplexml_load_string($this->zipEntry('xl/styles.xml'))->cellXfs->xf as $xf) {
            $text[] = (int) $xf['numFmtId'] === 49;
        }

        return array_keys(array_filter($text));
    }

    /** @return list<string> attendance cell refs with a solid fill */
    private function greyCells(): array
    {
        $styles = simplexml_load_string($this->zipEntry('xl/styles.xml'));
        $fills = [];
        foreach ($styles->fills->fill as $fill) {
            $fills[] = (string) $fill->patternFill['patternType'] === 'solid';
        }
        $solid = [];
        foreach ($styles->cellXfs->xf as $xf) {
            $solid[] = $fills[(int) $xf['fillId']] ?? false;
        }

        return array_keys(array_filter($this->cellStyles(), fn (int $s) => $solid[$s] ?? false));
    }
}
