<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeManagerAssignment;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Modules\Sites\Infrastructure\Eloquent\SiteAllowanceRule;
use Database\Seeders\SitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Fixture data is fake: placeholder names, IDs of the 2900101010000x form. */
class ImportMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-26';

    private User $hr;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->makeUser('hr');
    }

    protected function tearDown(): void
    {
        array_map(fn (string $f) => @unlink($f), $this->files);
        parent::tearDown();
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $before = $this->counts();

        [$code, $out] = $this->import($this->workbook($this->valid()));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertMatchesRegularExpression('/employees\s*\|\s*3\b/', $out);   // the dry run did the work, then rolled it back
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function commit_creates_everything_audited(): void
    {
        $manager = $this->makeUser('manager');
        $report = $this->files[] = sys_get_temp_dir().'/master-report-'.uniqid().'.csv';   // not tempnam(): that file is already 0600
        $sheets = $this->valid();
        $sheets['employees'][2]['manager_email'] = $manager->email;

        [$code, $out] = $this->import($this->workbook($sheets), ['--commit' => true, '--report' => $report]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('COMMITTED', $out);
        $this->assertSame([1, 2, 3], Employee::orderBy('code')->pluck('code')->all());
        foreach (Employee::with(['typeChanges', 'payChanges'])->get() as $e) {
            $this->assertSame([self::FROM], $e->typeChanges->pluck('effective_from')->map(fn ($d) => substr((string) $d, 0, 10))->all());
            $this->assertSame([self::FROM], $e->payChanges->pluck('effective_from')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        }
        $office = Employee::where('code', 1)->firstOrFail();
        $this->assertSame(Residence::where('name_ar', 'سكن أ')->value('id'), $office->residence_id);
        $this->assertSame('cash', $office->pay_method);
        $day = Employee::where('code', 3)->firstOrFail();
        $this->assertSame(0, bccomp('330.25', (string) $day->payChanges->first()?->daily_wage, 6));

        $rule = SiteAllowanceRule::sole();
        $this->assertSame('share_of_wage', $rule->mode);
        $this->assertSame(0, bccomp('0.5', (string) $rule->value, 6));
        $this->assertSame(Site::where('name_ar', 'موقع أ')->value('id'), Fare::sole()->site_id);
        $this->assertSame((string) $day->id, EmployeeFareOverride::sole()->employee_id);
        $this->assertSame($manager->id, EmployeeManagerAssignment::sole()->manager_id);

        $actors = DB::table('audit_log')->where('entity_type', 'employees')->pluck('user_id')->unique()->values()->all();
        $this->assertSame([$this->hr->id], $actors);

        $this->assertSame(0600, fileperms($report) & 0777);
        $this->assertSame(['ref,code', 'OFF-1,1', 'ENG-1,2', 'DAY-1,3'], file($report, FILE_IGNORE_NEW_LINES));
    }

    #[Test]
    public function bad_rows_roll_back_everything_and_list_all_errors(): void
    {
        $before = $this->counts();
        $sheets = $this->valid();
        $sheets['employees'] = [
            $this->employee('OFF-1', 'office', ['national_id' => '29001010100001', 'salary' => '9000']),   // row 2, fine
            $this->employee('OFF-2', 'office', ['national_id' => '2900101010000', 'salary' => '9000']),    // row 3, 13 digits
            $this->employee('OFF-3', 'office', ['residence' => 'سكن مجهول', 'salary' => '9000']),          // row 4
            $this->employee('OFF-4', 'office', ['national_id' => '29001010100001', 'salary' => '9000']),   // row 5, repeats row 2
            $this->employee('ENG-1', 'engineer', ['fingerprint_name' => 'fp x']),                          // row 6, no salary
            $this->employee('ENG-2', 'engineer', ['fingerprint_name' => 'fp x', 'salary' => '12000']),     // row 7, repeats row 6 (unwritten)
        ];
        $sheets['fare_overrides'] = [['ref' => 'OFF-1', 'site' => 'موقع أ', 'amount' => '30']];

        [$code, $out] = $this->import($this->workbook($sheets), ['--commit' => true]);

        $this->assertNotSame(0, $code);
        foreach (['employees row 3 national_id', 'employees row 4 residence', 'employees row 5 national_id', 'employees row 6 salary', 'employees row 7 fingerprint_name'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
        $this->assertSame(5, preg_match_all('/^\s*\w+ row \d+ /m', $out), $out);
        $this->assertStringContainsString('nothing was saved', $out);
        $this->assertStringNotContainsString('2900101010000', $out);   // no cell values in the output
        $this->assertStringNotContainsString('سكن مجهول', $out);
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function a_database_refusal_is_a_row_error_without_cell_values(): void
    {
        $before = $this->counts();
        $sheets = $this->valid();
        $sheets['employees'][1]['name_ar'] = str_repeat('س', 300);   // the column is varchar(255) and no rule says so

        [$code, $out] = $this->import($this->workbook($sheets), ['--commit' => true]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('employees row 3', $out);
        $this->assertMatchesRegularExpression('/employees\s*\|\s*2\b/', $out);   // the transaction survives: the row after it still ran
        $this->assertStringNotContainsString('29001010100002', $out);
        $this->assertStringNotContainsString('سسس', $out);
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function names_match_after_normalising(): void
    {
        $this->seed(SitesSeeder::class);
        $seeded = Residence::where('name_ar', 'دمنهور')->value('id');
        $sheets = [
            'residences' => [['name_ar' => 'دمنهور']],
            'sites' => [$this->site('موقع  أ ')],
            'fares' => [['residence' => 'دمنهور', 'site' => 'موقع أ', 'amount' => '25']],
            'employees' => [$this->employee('DAY-1', 'day_laborer', ['daily_wage' => '330', 'residence' => 'دمنهور'])],
            'fare_overrides' => [['ref' => 'DAY-1', 'site' => ' موقع   أ', 'amount' => '30']],
        ];

        [$code, $out] = $this->import($this->workbook($sheets), ['--commit' => true]);

        $this->assertSame(0, $code, $out);
        $site = Site::where('kind', 'work')->sole();
        $this->assertSame('موقع أ', $site->name_ar);
        $this->assertSame(1, Residence::where('name_ar', 'دمنهور')->count());
        $this->assertSame([$seeded, $site->id], [Fare::sole()->residence_id, Fare::sole()->site_id]);
        $this->assertSame($site->id, EmployeeFareOverride::sole()->site_id);
        $this->assertSame($seeded, Employee::sole()->residence_id);
    }

    #[Test]
    public function unknown_manager_is_a_row_error(): void
    {
        $inactive = $this->makeUser('manager', ['is_active' => false]);
        $sheets = $this->valid();
        $sheets['employees'][0]['manager_email'] = 'nobody@smart.test';
        $sheets['employees'][1]['manager_email'] = $inactive->email;

        [$code, $out] = $this->import($this->workbook($sheets), ['--commit' => true]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('employees row 2 manager_email', $out);
        $this->assertStringContainsString('employees row 3 manager_email', $out);
        $this->assertStringNotContainsString('nobody@smart.test', $out);
        $this->assertSame(0, Employee::withTrashed()->count());
    }

    #[Test]
    public function refuses_when_employees_exist(): void
    {
        $this->import($this->workbook($this->valid()), ['--commit' => true]);
        Employee::query()->get()->each->delete();   // soft-deleted rows still count
        $residences = Residence::count();

        [$code, $out] = $this->import($this->workbook($this->valid()), ['--commit' => true]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Employees already exist; this import is for an empty system.', $out);
        $this->assertSame(3, Employee::withTrashed()->count());
        $this->assertSame($residences, Residence::count());
    }

    #[Test]
    public function refuses_actor_without_permission(): void
    {
        $before = $this->counts();

        [$code, $out] = $this->import($this->workbook($this->valid()), ['--commit' => true, '--as' => $this->makeUser('manager')->email]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('lacks', $out);
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function refuses_an_unwritable_report_path_before_importing(): void
    {
        $before = $this->counts();

        [$code, $out] = $this->import($this->workbook($this->valid()), ['--commit' => true, '--report' => '/nonexistent-dir/r.csv']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('The report file cannot be created', $out);
        $this->assertStringContainsString('nothing was saved', $out);
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function refuses_a_malformed_workbook_before_any_write(): void
    {
        $before = $this->counts();
        $valid = $this->valid();
        $duplicate = $this->workbook([...$valid, 'sites' => [['name_ar', 'name_en', 'name_en'], ['موقع أ', 'Site A', 'A'], ['موقع ب', 'Site B', 'B']]]);   // valid but for the repeat
        $unknown = $this->valid();
        $unknown['employees'][0]['salry'] = '9000';
        $misnamed = $this->valid();
        $misnamed['fare_override'] = $misnamed['fare_overrides'];   // a typo would silently drop the overrides
        unset($misnamed['fare_overrides']);
        unset($valid['employees']);

        foreach ([$duplicate, $this->workbook($unknown), $this->workbook($misnamed), $this->workbook($valid)] as $path) {
            [$code, $out] = $this->import($path, ['--commit' => true]);
            $this->assertNotSame(0, $code, $out);
            $this->assertStringContainsString('nothing was saved', $out);
        }
        $this->assertSame($before, $this->counts());
    }

    /** @return array{int, string} exit code and output */
    private function import(string $path, array $options = []): array
    {
        $code = Artisan::call('hrms:import-master', ['file' => $path, '--as' => $this->hr->email, '--from' => self::FROM, ...$options]);

        return [$code, Artisan::output()];
    }

    /** @return array<string, list<array<string, ?string>>> sheet => rows keyed by header */
    private function valid(): array
    {
        return [
            'residences' => [['name_ar' => 'سكن أ'], ['name_ar' => 'سكن ب']],
            'sites' => [$this->site('موقع أ', ['allowance_mode' => 'share_of_wage', 'allowance_value' => '0.5']), $this->site('موقع ب')],
            'fares' => [['residence' => 'سكن أ', 'site' => 'موقع أ', 'amount' => '25']],
            'employees' => [
                $this->employee('OFF-1', 'office', ['national_id' => '29001010100001', 'salary' => '9000', 'insured_basic' => '5000', 'residence' => 'سكن أ']),
                $this->employee('ENG-1', 'engineer', ['national_id' => '29001010100002', 'salary' => '12000']),
                $this->employee('DAY-1', 'day_laborer', ['daily_wage' => '330.25', 'fingerprint_name' => 'fp day']),
            ],
            'fare_overrides' => [['ref' => 'DAY-1', 'site' => 'موقع أ', 'amount' => '30']],
        ];
    }

    /** @return array<string, ?string> */
    private function site(string $name, array $over = []): array
    {
        return ['name_ar' => $name, 'name_en' => 'Site', 'outside_alexandria' => '0', 'allowance_mode' => null, 'allowance_value' => null, 'excel_spellings' => 'info', ...$over];
    }

    /** @return array<string, ?string> */
    private function employee(string $ref, string $type, array $over = []): array
    {
        return [
            'ref' => $ref, 'type' => $type, 'name_ar' => 'موظف '.$ref, 'name_en' => null, 'fingerprint_name' => null, 'gender' => 'male',
            'national_id' => null, 'insurance_no' => null, 'phone' => null, 'address' => null, 'department' => null, 'residence' => null,
            'pay_method' => null, 'bank_account' => null, 'hire_date' => '2020-01-01', 'salary' => null, 'raise' => null, 'insured_basic' => null,
            'daily_wage' => null, 'manager_email' => null, 'hr_notes' => null, 'source' => 'fixture', ...$over,
        ];
    }

    /** @param  array<string, list<array<int|string, ?string>>>  $sheets  keyed rows (headers from the first row's keys) or raw lists (first row = headers) */
    private function workbook(array $sheets): string
    {
        $path = $this->tempPath();
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $rows) {
            $first ? $first = false : $writer->addNewSheetAndMakeItCurrent();
            $writer->getCurrentSheet()->setName($name);
            $raw = array_is_list($rows[0] ?? []) ? $rows : [array_keys($rows[0]), ...array_map(array_values(...), $rows)];
            foreach ($raw as $values) {
                $writer->addRow(Row::fromValues($values));
            }
        }
        $writer->close();

        return $path;
    }

    private function tempPath(): string
    {
        return $this->files[] = (string) tempnam(sys_get_temp_dir(), 'master-');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'employees' => Employee::withTrashed()->count(),
            'work_sites' => Site::where('kind', 'work')->count(),
            'residences' => Residence::count(),
            'fares' => Fare::count(),
            'rules' => SiteAllowanceRule::count(),
            'overrides' => EmployeeFareOverride::count(),
            'audit' => DB::table('audit_log')->count(),
        ];
    }
}
