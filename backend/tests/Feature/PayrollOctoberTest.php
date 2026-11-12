<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceLocks;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Domain\Component;
use App\Modules\Payroll\Domain\DayInput;
use App\Modules\Payroll\Domain\OfficeEngine;
use App\Modules\Payroll\Infrastructure\Eloquent\PayrollEntry;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * The October 2026 regression (design 2026-11-09, "October 2026 regression and the Excel-difference report"): the three anonymized
 * workbooks (Task B9 fixtures) run through the system, and every per-person difference from the Excel must be a line of
 * expected-differences.json. Field and day laborers are seeded and read through the payroll API; office runs at engine level on the
 * workbook's per-person totals. Amounts are compared at 6 decimals, half-up, as bcmath strings: the Excel values carry float noise.
 *
 * A difference's amount is its effect on the net (system − Excel, times the component's sign), so per workbook
 * Σ system net = Excel total + Σ differences. The Excel total is Σ net over the rows its SUBTOTAL(9) counts (`in_excel_total`); a
 * row its AutoFilter hides is paid by the system and explained at total level (`excel_filtered_row`, provisional ruling).
 */
class PayrollOctoberTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../Fixtures/payroll/2026-10/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-12 10:00');   // after every period: no day is future
        $this->signIn('hr');
    }

    public function test_field_october(): void
    {
        $f = self::fixture('field');
        $this->seedFixture($f);
        $system = [];
        foreach ([EmployeeType::Engineer, EmployeeType::PermanentWorker] as $group) {
            app(AttendanceLocks::class)->lock($group, '2026-09-26', true);   // the unrecorded days stay missing (confirmed)
            $system += $this->sheet("/api/v1/payroll/{$group->value}/2026-09-26");
        }

        $this->assertWorkbook($f, $system, '315451');
    }

    public function test_day_laborers_october(): void
    {
        $f = self::fixture('day-laborers');
        $this->seedFixture($f);
        app(AttendanceLocks::class)->lock(EmployeeType::DayLaborer, '2026-10-01', true);

        $this->assertWorkbook($f, $this->sheet('/api/v1/payroll/day_laborer/2026-10-01'), '391948');
    }

    public function test_office_october(): void
    {
        // The office workbook holds per-person totals only (no days): the test builds the month the workbook states. Every day is
        // present with no in time (column P, late minutes net of grace, is 0 for all 27), but the last Y days (ايام غياب), which are
        // absent (غ). HR's period inputs go on the entry. Wage = salary + raise; no salary (Person-26) is no pay row.
        $f = self::fixture('office');
        $rates = app(PayrollInputs::class)->rates(EmployeeType::Office);
        $system = [];
        foreach ($f['people'] as $p) {
            $e = $p['entry'];
            $this->assertSame(0, $e['late_minutes'], "{$p['label']}: late minutes need synthesised late days");
            $wage = $p['salary'] === null ? null : bcadd($p['salary'], $p['raise'] ?? '0', 6);
            $absent = 31 - ($e['absence_days'] ?? 0);
            $days = [];
            foreach (range(1, 31) as $n) {
                $days[] = new DayInput(
                    sprintf('2026-10-%02d', $n), $n > $absent ? 'leave' : 'work', $n > $absent ? 'absent' : null, null, null, null, '09:00', '17:00',
                    0, 0, 0, null, 'none', null, $wage, '0', '0',
                );
            }
            $line = OfficeEngine::compute($days, $rates, [
                'lateness_exemption_minutes' => $e['lateness_exemption_minutes'], 'ot_minutes' => $e['ot_minutes'],
                'travel_days' => $e['travel_days'], 'tower_days' => $e['tower_days'], 'bonus' => $e['bonus'], 'negligence' => $e['negligence'],
                'advances' => $e['advances'], 'insurance_employee' => $e['insurance_employee'], 'income_tax' => $e['income_tax'],
                'insured_basic' => $p['insured_basic'],
            ]);
            $system[$p['label']] = $line->components;
        }

        $this->assertWorkbook($f, $system, null);
    }

    public function test_the_committed_report_is_what_the_command_prints(): void
    {
        $this->assertSame(0, Artisan::call('payroll:excel-differences'));
        $committed = base_path('../docs/reports/2026-10-excel-differences.md');
        $this->assertFileExists($committed, 'docs/ must be mounted beside backend/, read-only: compose ./docs:/var/www/docs:ro, or docker run -v "$WT/docs:/var/www/docs:ro".');
        $this->assertSame(file_get_contents($committed), Artisan::output(),
            'Regenerate it: php artisan payroll:excel-differences > docs/reports/2026-10-excel-differences.md');
    }

    public function test_the_client_copy_takes_the_names_and_is_written_beside_the_map_outside_the_repository(): void
    {
        $dir = sys_get_temp_dir().'/'.uniqid('names-');
        mkdir($dir);
        $map = json_encode(['people' => ['Worker-1' => 'Name One', 'Worker-10' => ''], 'sites' => ['Site-8' => 'Site Name']]);
        file_put_contents("{$dir}/map.json", $map);

        $this->assertSame(0, Artisan::call('payroll:excel-differences', ['--names' => "{$dir}/map.json"]));
        $copy = (string) file_get_contents("{$dir}/2026-10-excel-differences-client.md");
        $this->assertSame(0600, fileperms("{$dir}/2026-10-excel-differences-client.md") & 0777, 'The copy with names is readable by its owner only.');
        array_map(unlink(...), glob("{$dir}/*") ?: []);
        rmdir($dir);
        $this->assertStringContainsString('| Name One | المواصلات |', $copy);
        $this->assertStringContainsString('الأجرة في Site Name هي 20', $copy);
        $this->assertStringContainsString('| Worker-10 |', $copy);   // a blank name keeps the label, and Worker-1 is not a prefix of it
        $this->assertStringNotContainsString('| Worker-1 |', $copy);

        $inside = base_path('storage/framework/map.json');   // inside the repository: refused, nothing written
        file_put_contents($inside, $map);
        try {
            $this->assertSame(1, Artisan::call('payroll:excel-differences', ['--names' => $inside]));
            $this->assertFileDoesNotExist(base_path('storage/framework/2026-10-excel-differences-client.md'));
        } finally {
            unlink($inside);
        }
    }

    public function test_every_expected_line_names_a_person_of_the_three_workbooks(): void
    {
        $labels = [];
        foreach (['field', 'day-laborers', 'office'] as $workbook) {
            $labels = [...$labels, ...array_column(self::fixture($workbook)['people'], 'label')];
        }

        $this->assertSame([], array_values(array_diff(array_column(self::fixture('expected-differences'), 'label'), $labels)));
    }

    /**
     * Every per-person difference is the sum of its expected lines, and no expected line is left over; then the workbook total.
     *
     * @param  array<string, mixed>  $f  the fixture
     * @param  array<string, array<string, string>>  $system  label → component → final amount
     */
    private function assertWorkbook(array $f, array $system, ?string $excelTotal): void
    {
        $people = array_column($f['people'], null, 'label');
        $this->assertEqualsCanonicalizing(array_keys($people), array_keys($system), 'Every person of the workbook is on the sheet.');

        $actual = [];
        $netDiffs = [];
        $total = '0';
        foreach ($people as $label => $p) {
            $excel = $p['excel'] ?? [];
            $netDiff = bcsub($system[$label]['net'], self::six($excel['net'] ?? null), 6);
            if (bccomp($netDiff, '0', 6) !== 0) {
                $netDiffs[$label] = $netDiff;
            }
            foreach (self::asExcel($p['type'], $system[$label]) as $component => [$amount, $sign]) {
                $diff = bcmul((string) $sign, bcsub($amount, self::six($excel[$component] ?? null), 6), 6);
                if (bccomp($diff, '0', 6) !== 0) {
                    $actual["{$label}|{$component}"] = $diff;
                }
            }
            if ($p['in_excel_total'] ?? true) {
                $total = bcadd($total, self::six($excel['net'] ?? null), 6);
            } elseif (bccomp(self::six($excel['net'] ?? null), '0', 6) !== 0) {
                $actual["{$label}|net"] = self::six($excel['net']);   // paid by the system, left out of the Excel's SUBTOTAL(9)
            }
        }

        $expected = [];
        $byPerson = [];
        $explained = '0';
        foreach (self::fixture('expected-differences') as $d) {
            if (isset($people[$d['label']])) {
                $key = "{$d['label']}|{$d['component']}";
                $expected[$key] = bcadd($expected[$key] ?? '0', $d['amount'], 6);
                $explained = bcadd($explained, $d['amount'], 6);
                if ($d['component'] !== 'net') {   // a hidden row's net line moves the total, not the person's net
                    $byPerson[$d['label']] = bcadd($byPerson[$d['label']] ?? '0', $d['amount'], 6);
                }
            }
        }
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual, 'Each difference from the Excel is explained by expected-differences.json, and nothing more.');
        $byPerson = array_filter($byPerson, fn (string $a) => bccomp($a, '0', 6) !== 0);
        ksort($byPerson);
        ksort($netDiffs);
        $this->assertSame($byPerson, $netDiffs, 'Each person: system net − Excel net = Σ their expected lines.');

        if ($excelTotal !== null) {
            $this->assertSame($excelTotal, bcround($total, 0), 'The Excel total is its المجمع total, to the pound.');
        }
        $net = '0';
        foreach ($system as $components) {
            $net = bcadd($net, $components['net'], 6);
        }
        $this->assertSame(bcadd($total, $explained, 6), $net, 'System total = Excel total + Σ differences.');
    }

    /**
     * The system line in the Excel's columns, each with its sign in the net (Component::sign). The field المجمع has one `extra` column
     * (D): an engineer's day money J = OT − lateness + outside, its G (lateness) 0; a permanent worker's O = OT money + outside money,
     * its lateness in G.
     *
     * @param  array<string, string>  $c  component → amount
     * @return array<string, array{string, int}> Excel column → [amount, sign]
     */
    private static function asExcel(string $type, array $c): array
    {
        $view = [];
        foreach ($c as $component => $amount) {
            $sign = Component::from($component)->sign();
            if ($sign !== 0) {
                $view[$component] = [$amount, $sign];
            }
        }
        if ($type === 'engineer' || $type === 'permanent_worker') {
            $extra = bcadd($c['overtime'], $c['outside'], 6);
            if ($type === 'engineer') {
                $extra = bcsub($extra, $c['lateness'], 6);
                $view['lateness'] = ['0', -1];
            }
            unset($view['overtime'], $view['outside']);
            $view['extra'] = [$extra, 1];
        }

        return $view;
    }

    /**
     * Sites, people, their days and entries, as the Excel holds them. A day with no site is unrecorded (missing: the workbook was
     * saved mid-period) and a day that is not in the period (undated) has nowhere to go; both are left out.
     *
     * @param  array<string, mixed>  $f
     */
    private function seedFixture(array $f): void
    {
        $leave = $this->leaveTypes();
        $sites = [];
        foreach ($f['sites'] as $s) {
            if ($s['kind'] === 'leave') {
                $sites[$s['label']] = [$leave[$s['leave_code']], true];

                continue;
            }
            $site = Site::create(['name_ar' => $s['label'], 'kind' => 'work', 'outside_alexandria' => $s['outside_alexandria']]);
            if ($s['allowance']['mode'] !== 'none') {
                $site->allowanceRules()->create(['effective_from' => '2026-01-01', ...$s['allowance']]);
            }
            $sites[$s['label']] = [(string) $site->getKey(), false];
        }

        $start = $f['period']['start'];
        foreach ($f['people'] as $n => $p) {
            $employee = Employee::create(['code' => $n + 1, 'name_ar' => $p['label'], 'gender' => 'male', 'hire_date' => '2026-01-01']);
            $employee->typeChanges()->create(['type' => $p['type'], 'effective_from' => '2026-01-01']);
            $wage = $p['type'] === 'day_laborer' ? ['daily_wage' => $p['daily_wage']] : ['salary' => $p['salary'], 'raise' => $p['raise'] ?? '0'];
            if (reset($wage) !== null) {   // no wage in the workbook: no pay row (the engines pay 0 and flag no_wage)
                $employee->payChanges()->create(['effective_from' => '2026-01-01', ...$wage]);
            }
            $id = (string) $employee->getKey();
            $fares = $p['type'] === 'permanent_worker' ? $this->fares($id, $p['days'], $sites) : [];
            foreach ($p['days'] as $d) {
                if ($d['site'] !== null) {
                    AttendanceDay::create(['employee_id' => $id, 'date' => $d['date'], 'source' => 'imported', 'version' => 1,
                        ...self::day($p['type'], $d, $sites[$d['site']], $fares)]);
                }
            }
            $entry = array_filter($p['entry'], fn ($v) => $v !== null && bccomp($v, '0', 6) !== 0);
            if ($entry !== []) {
                PayrollEntry::create(['employee_id' => $id, 'employee_type' => $p['type'], 'period_start' => $start, 'version' => 1, ...$entry]);
            }
        }
    }

    /**
     * A day's stored values, the fields its type and site take (DayRules::FIELDS): a leave day holds only its advance (and a permanent
     * worker's transport none). A time cell that held a typed number reads null (Task B9): the day then stores neither time, since one
     * time alone would be an open day the Excel never had. Amounts are stored only above 0 (attendance_days checks).
     *
     * @param  array<string, mixed>  $d
     * @param  array{string, bool}  $site  id, is a leave day-type
     * @param  array<string, string>  $fares  site id → the worker's fare
     * @return array<string, mixed>
     */
    private static function day(string $type, array $d, array $site, array $fares): array
    {
        [$siteId, $isLeave] = $site;
        $amount = fn (?string $v) => $v !== null && bccomp($v, '0', 6) > 0 ? $v : null;
        $values = ['site_id' => $siteId, 'advance_amount' => $amount($d['advance'])];
        if ($isLeave) {
            return [...$values, 'transport' => $type === 'permanent_worker' ? 'none' : null];
        }
        $times = $d['in'] !== null && $d['out'] !== null ? ['in_time' => $d['in'], 'out_time' => $d['out']] : [];

        return [...$values, ...$times, ...match ($type) {
            'engineer' => ['late_minutes' => $d['late'], 'ot_minutes' => $d['ot'], 'outside_minutes' => $d['outside_minutes']],
            'day_laborer' => ['late_minutes' => $d['late'], 'ot_minutes' => $d['ot']],
            'permanent_worker' => [
                'scheduled_in' => $d['scheduled_in'], 'scheduled_out' => $d['scheduled_out'], 'outside_amount' => $amount($d['outside_amount']),
                'transport' => self::portion($d['transport'], $fares[$siteId] ?? null),
            ],
        }];
    }

    /**
     * A permanent worker's typed per-day transport money (4 workers; Task B9) as the system holds it: per site, a per-worker fare
     * override = the amount typed on most of the worker's work days there (ties: the larger), from before the period.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, array{string, bool}>  $sites
     * @return array<string, string> site id → fare
     */
    private function fares(string $employeeId, array $days, array $sites): array
    {
        $typed = [];
        foreach ($days as $d) {
            if ($d['site'] !== null && ! $sites[$d['site']][1] && $d['transport'] !== null && bccomp($d['transport'], '0', 6) > 0) {
                $typed[$sites[$d['site']][0]][] = $d['transport'];
            }
        }
        $fares = [];
        foreach ($typed as $siteId => $amounts) {
            $counts = array_count_values($amounts);
            uksort($counts, fn ($a, $b) => $counts[$b] <=> $counts[$a] ?: bccomp((string) $b, (string) $a, 6));
            $fares[$siteId] = (string) array_key_first($counts);
            EmployeeFareOverride::create(['employee_id' => $employeeId, 'site_id' => $siteId, 'effective_from' => '2026-01-01', 'amount' => $fares[$siteId]]);
        }

        return $fares;
    }

    /** The day's portion of the fare (§3): none for 0, half for half the fare, else full; null when the worker has no such column. */
    private static function portion(?string $typed, ?string $fare): ?string
    {
        return match (true) {
            $typed === null => null,
            bccomp($typed, '0', 6) === 0 => 'none',
            $fare !== null && bccomp(bcmul($typed, '2', 6), $fare, 6) === 0 => 'half',
            default => 'full',
        };
    }

    /** @return array<string, array<string, string>> label → component → final amount */
    private function sheet(string $url): array
    {
        $lines = [];
        foreach ($this->getJson($url)->assertOk()->json('data.lines') as $line) {
            $lines[$line['employee']['name_ar']] = array_map(fn (array $c) => $c['final'], $line['components']);
        }

        return $lines;
    }

    /** An Excel amount at 6 decimals, half-up; blank is 0. */
    private static function six(?string $v): string
    {
        return bcadd(bcround($v ?? '0', 6), '0', 6);
    }

    /** @return array<mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES."{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
