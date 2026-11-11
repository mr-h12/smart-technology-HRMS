<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceLocks;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Payroll\Application\PayrollSheet;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * The payroll sheet (design 2026-11-09, "Shape"): the inputs assembler over the stored days and dated records, the engines, and HR's
 * entries and overrides applied on read. Field shift 08:00–17:00 (the seeded setting). S = 9000: day rate 300, minute rate 0.625.
 */
class PayrollSheetTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-05 10:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite();
    }

    public function test_sheet_lists_each_employee_of_the_group_with_totals(): void
    {
        $first = $this->employee('engineer');
        $leaver = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-05']);   // archived today, still paid for the period
        $this->employee('permanent_worker');
        $this->employee('engineer', '2026-10-26');   // hired after the period
        $this->day($first, '2026-10-01', ['ot_minutes' => 60]);   // 60 × 0.625 × 1.5 = 56.25

        $sheet = $this->sheet()->sheet($this->engineers());

        $this->assertFalse($sheet['locked']);
        $this->assertSame([$first, $leaver], array_column(array_column($sheet['lines'], 'employee'), 'id'));
        $line = $sheet['lines'][0];
        $this->assertSame(['id', 'code', 'name_ar', 'name_en'], array_keys($line['employee']));
        $this->assertSame('Engineer-1', $line['employee']['name_ar']);
        $this->assertSame(['computed' => '56.250000', 'override' => null, 'final' => '56.250000'], $line['components']['overtime']);
        $this->assertSame('9056.250000', $line['components']['net']['final']);
        $this->assertSame(['missing'], $line['flags']);
        $this->assertNull($line['entry']['version']);
        $this->assertArrayNotHasKey('days', $line);
        // The leaver: Oct 6–25 not employed, 20 × 300 (calculations §10).
        $this->assertSame('6000.000000', $sheet['lines'][1]['components']['not_employed']['final']);
        $this->assertSame('3000.000000', $sheet['lines'][1]['components']['net']['final']);
        $this->assertSame('18000.000000', $sheet['totals']['salary']);
        $this->assertSame('6000.000000', $sheet['totals']['not_employed']);
        $this->assertSame('12056.250000', $sheet['totals']['net']);
    }

    public function test_type_change_at_boundary_splits_sheets(): void
    {
        // Review Focus 1: an engineer becomes a day laborer on 2026-08-01, a day-laborer period start (calculations §10, R32 default).
        $this->cairo('2026-08-27 10:00');
        $id = $this->employee('engineer');
        app(EmployeeService::class)->addTypeChange($id, ['type' => 'day_laborer', 'effective_from' => '2026-08-01']);
        app(EmployeeService::class)->addPayChange($id, ['effective_from' => '2026-08-01', 'daily_wage' => '330']);
        // A legacy row may also hold the other type's fields: a day-laborer day reads only the daily wage.
        DB::table('employee_pay_changes')->where(['employee_id' => $id, 'effective_from' => '2026-08-01'])->update(['salary' => '12000']);
        foreach (['2026-07-26', '2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-08-03'] as $date) {
            $this->day($id, $date);
        }

        $period = Period::startingAt(EmployeeType::Engineer, '2026-07-26');
        $engineer = $this->sheet()->employee($period, $id);

        // One day per calendar day, in date order: Jul 26–30 worked, Jul 31 the Friday off, the rest not employed as an engineer.
        $this->assertSame($period->days(), array_column($engineer['days'], 'date'));
        $this->assertSame(
            [...array_fill(0, 5, 'work'), 'leave', ...array_fill(0, 25, 'not_employed')],
            array_column($engineer['days'], 'state'),
        );
        $this->assertSame('9000.000000', $engineer['components']['salary']['final']);
        $this->assertSame('7500.000000', $engineer['components']['not_employed']['final']);   // 25 × 300
        $this->assertSame('1500.000000', $engineer['components']['net']['final']);

        $laborers = $this->sheet()->sheet(Period::startingAt(EmployeeType::DayLaborer, '2026-08-01'));
        $this->assertSame([$id], array_column(array_column($laborers['lines'], 'employee'), 'id'));
        $this->assertSame('330.000000', $laborers['lines'][0]['components']['wages']['final']);
    }

    public function test_override_survives_and_warns(): void
    {
        // Review Focus 4.
        $id = $this->employee('engineer');
        $day = $this->day($id, '2026-10-01', ['ot_minutes' => 60]);   // 56.25
        $p = $this->engineers();

        $line = $this->sheet()->addOverride($p, $id, 'overtime', '100', 'Approved by HR');
        $override = $line['components']['overtime']['override'];
        $this->assertSame(['id' => $override['id'], 'amount' => '100.000000', 'reason' => 'Approved by HR', 'inputs_changed' => false], $override);
        $this->assertSame('100.000000', $line['components']['overtime']['final']);
        $this->assertSame('9100.000000', $line['components']['net']['final']);
        $this->assertNotContains('inputs_changed', $line['flags']);

        $day->update(['ot_minutes' => 120, 'version' => 2]);   // 112.5 computed now

        $line = $this->sheet()->employee($p, $id);
        $this->assertSame('112.500000', $line['components']['overtime']['computed']);
        $this->assertTrue($line['components']['overtime']['override']['inputs_changed']);
        $this->assertSame('100.000000', $line['components']['overtime']['final']);
        $this->assertSame('9100.000000', $line['components']['net']['final']);
        $this->assertContains('inputs_changed', $line['flags']);

        $line = $this->sheet()->revertOverride($p, $id, $override['id'], 'Typed in error');
        $this->assertSame(['computed' => '112.500000', 'override' => null, 'final' => '112.500000'], $line['components']['overtime']);
        $this->assertSame('9112.500000', $line['components']['net']['final']);
        $this->assertNotContains('inputs_changed', $line['flags']);
        $audit = DB::table('audit_log')->where(['entity_type' => 'payroll_overrides', 'entity_id' => $override['id']])->get();
        $this->assertEqualsCanonicalizing(['CREATED', 'UPDATED', 'DELETED'], $audit->pluck('event')->all());
        $this->assertSame('Typed in error', json_decode((string) $audit->firstWhere('event', 'UPDATED')->new_values, true)['revert_reason']);
    }

    public function test_net_override_wins(): void
    {
        $id = $this->employee('engineer');
        $p = $this->engineers();
        $this->sheet()->addOverride($p, $id, 'overtime', '100', 'Approved by HR');

        $line = $this->sheet()->addOverride($p, $id, 'net', '-50.5', 'Settled by hand');

        $this->assertSame('9000.000000', $line['components']['net']['computed']);
        $this->assertSame('-50.500000', $line['components']['net']['final']);
        $this->assertContains('negative_net', $line['flags']);   // re-evaluated on the final net
        $this->assertSame('-50.500000', $this->sheet()->sheet($p)['totals']['net']);
    }

    public function test_unlocked_period_is_marked(): void
    {
        $this->employee('engineer');
        $p = $this->engineers();

        $this->assertFalse($this->sheet()->sheet($p)['locked']);
        app(AttendanceLocks::class)->lock(EmployeeType::Engineer, $p->start, true);
        $this->assertTrue($this->sheet()->sheet($p)['locked']);
    }

    public function test_engineer_days_take_the_field_shift_and_only_a_fare_override(): void
    {
        $residence = $this->residenceWithFare('40');
        $id = $this->employee('engineer', '2026-01-01', ['residence_id' => $residence, 'insured_basic' => '5000']);
        $this->day($id, '2026-10-01', ['out_time' => '12:00', 'late_minutes' => 25]);
        $p = $this->engineers();

        $line = $this->sheet()->employee($p, $id);
        $this->assertSame('0.000000', $line['components']['transport']['final']);   // the residence × site fare is never an engineer's (R2)
        $this->assertSame('9.375000', $line['components']['lateness']['final']);    // (25 − 15) × 0.625 × 1.5, the engineer multiplier
        $this->assertSame('5000.000000', $line['components']['insured_basic']['final']);   // from the pay row
        $this->assertContains('early_departure:300', $line['days'][5]['flags']);   // the field shift ends 17:00
        $this->assertNotContains('no_fare', $line['flags']);

        EmployeeFareOverride::create(['employee_id' => $id, 'site_id' => $this->site, 'effective_from' => '2026-10-01', 'amount' => '25']);
        $this->assertSame('25.000000', $this->sheet()->employee($p, $id)['components']['transport']['final']);
    }

    public function test_day_laborer_days_take_the_site_allowance_the_fare_and_the_shift(): void
    {
        $residence = $this->residenceWithFare('40');
        Site::findOrFail($this->site)->allowanceRules()->create(['effective_from' => '2026-01-01', 'mode' => 'share_of_wage', 'value' => '0.25']);
        $id = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $residence]);
        $this->day($id, '2026-10-01', ['out_time' => '12:00', 'late_minutes' => 25, 'ot_minutes' => 60]);

        $line = $this->sheet()->employee(Period::startingAt(EmployeeType::DayLaborer, '2026-10-01'), $id);

        $this->assertSame('330.000000', $line['components']['wages']['final']);
        $this->assertSame('82.500000', $line['components']['allowance']['final']);   // 330 × 0.25
        $this->assertSame('40.000000', $line['components']['transport']['final']);
        $this->assertSame('61.875000', $line['components']['overtime']['final']);    // 60 × 330 ÷ 480 × 1.5
        $this->assertSame('13.750000', $line['components']['lateness']['final']);    // (25 − 15) × 330 ÷ 480 × 2, the day-laborer multiplier
        $this->assertSame('500.625000', $line['components']['net']['final']);
        $this->assertContains('early_departure:300', $line['days'][0]['flags']);
        $this->assertSame(['early_departure', 'missing'], $line['flags']);
    }

    public function test_a_day_with_no_fare_or_no_pay_row_is_flagged(): void
    {
        $laborer = $this->employee('day_laborer');   // no residence: no fare anywhere
        $this->day($laborer, '2026-10-01');
        $this->day($laborer, '2026-10-03');
        $unpaid = $this->employee('day_laborer', '2026-10-01');
        DB::table('employee_pay_changes')->where('employee_id', $unpaid)->update(['effective_from' => '2026-10-05']);
        $this->day($unpaid, '2026-10-03');
        $worker = $this->employee('permanent_worker');
        $this->day($worker, '2026-10-01', ['scheduled_in' => '08:00', 'scheduled_out' => '17:00', 'transport' => 'full']);
        $noTransport = $this->employee('permanent_worker');
        $this->day($noTransport, '2026-10-01', ['scheduled_in' => '08:00', 'scheduled_out' => '17:00', 'transport' => 'none']);

        $laborers = $this->sheet()->sheet(Period::startingAt(EmployeeType::DayLaborer, '2026-10-01'))['lines'];
        $this->assertSame(['missing', 'no_fare'], $laborers[0]['flags']);   // once per line, however many days
        $this->assertSame('0.000000', $laborers[1]['components']['wages']['final']);
        $this->assertContains('no_wage', $laborers[1]['flags']);

        $workers = $this->sheet()->sheet($this->engineers(EmployeeType::PermanentWorker))['lines'];
        $this->assertContains('no_fare', $workers[0]['flags']);
        $this->assertNotContains('no_fare', $workers[1]['flags']);   // a day with no transport needs no fare
    }

    public function test_office_line_reads_the_office_shift_its_entry_and_the_insured_basic(): void
    {
        $id = $this->employee('office', '2026-01-01', ['insured_basic' => '5000']);
        AttendanceDay::create(['employee_id' => $id, 'date' => '2026-10-01', 'in_time' => '09:40', 'out_time' => '17:00', 'source' => 'entered', 'version' => 1]);
        $p = Period::startingAt(EmployeeType::Office, '2026-10-01');

        $line = $this->sheet()->saveEntry($p, $id, ['negligence' => '100', 'ot_minutes' => 120], null);

        $this->assertSame('15.625000', $line['components']['lateness']['final']);   // 09:40 − 09:00 − 15 grace = 25 × 0.625
        $this->assertSame('100.000000', $line['components']['negligence']['final']);
        $this->assertSame('112.500000', $line['components']['overtime']['final']);  // 120 × 0.625 × 1.5
        $this->assertSame('5000.000000', $line['components']['insured_basic']['final']);
        $this->assertSame('4000.000000', $line['components']['incentives']['final']);
        $this->assertSame('8996.875000', $line['components']['net']['final']);     // 9000 + 112.5 − 15.625 − 100
        $this->assertSame(1, $line['entry']['version']);
        $this->assertSame('100.000000', $line['entry']['negligence']);
    }

    public function test_entry_is_versioned(): void
    {
        $id = $this->employee('engineer');
        $p = $this->engineers();

        $this->assertSame('8900.000000', $this->sheet()->saveEntry($p, $id, ['deduction' => '100', 'deduction_reason' => 'Late report'], null)['components']['net']['final']);
        $line = $this->sheet()->saveEntry($p, $id, ['deduction' => '200', 'deduction_reason' => 'Late report'], 1);
        $this->assertSame(2, $line['entry']['version']);
        $this->assertSame('8800.000000', $line['components']['net']['final']);
        // Each write is audited with its before and after values.
        $audit = DB::table('audit_log')->where('entity_type', 'payroll_entries')->get()->keyBy('event');
        $values = function (mixed $json): array {
            $values = json_decode((string) $json, true);
            ksort($values);

            return $values;
        };
        $this->assertSame(['CREATED', 'UPDATED'], $audit->keys()->sort()->values()->all());
        $this->assertSame(['deduction' => '100', 'deduction_reason' => 'Late report', 'version' => 1], array_intersect_key($values($audit['CREATED']->new_values), ['deduction' => 0, 'deduction_reason' => 0, 'version' => 0]));
        $this->assertSame(['deduction' => '100.000000', 'version' => 1], $values($audit['UPDATED']->old_values));
        $this->assertSame(['deduction' => '200', 'version' => 2], $values($audit['UPDATED']->new_values));

        try {
            $this->sheet()->saveEntry($p, $id, ['deduction' => '300'], 1);
            $this->fail('A stale version must conflict.');
        } catch (ConcurrencyConflict) {
            $this->assertSame('200.000000', $this->sheet()->employee($p, $id)['entry']['deduction']);
        }
        $this->expectException(ConcurrencyConflict::class);
        $this->sheet()->saveEntry($p, $this->employee('engineer'), ['deduction' => '1'], 1);   // no entry yet: only a null version creates one
    }

    public function test_an_override_needs_a_component_of_the_line_and_one_at_a_time(): void
    {
        $id = $this->employee('engineer');
        $p = $this->engineers();
        $this->sheet()->addOverride($p, $id, 'overtime', '100', 'Approved by HR');

        try {
            $this->sheet()->addOverride($p, $id, 'overtime', '120', 'Again');
            $this->fail('A second live override of a component must conflict.');
        } catch (ConcurrencyConflict) {
            $this->assertSame('100.000000', $this->sheet()->employee($p, $id)['components']['overtime']['final']);
        }
        try {
            DB::transaction(fn () => DB::table('payroll_overrides')->update(['deleted_at' => now()]));   // a revert with no reason at all
            $this->fail('A reverted override must carry its reason.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('payroll_overrides_revert_reason_check', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        $this->sheet()->addOverride($p, $id, 'travel', '100', 'Office only');   // an engineer's line has no travel
    }

    public function test_reverting_an_override_of_someone_off_the_sheet_writes_nothing(): void
    {
        $id = $this->employee('engineer');
        $p = $this->engineers();
        $override = $this->sheet()->addOverride($p, $id, 'overtime', '100', 'Approved by HR')['components']['overtime']['override']['id'];
        DB::table('employee_type_changes')->where('employee_id', $id)->update(['type' => 'office']);   // the type history edited since

        try {
            $this->sheet()->revertOverride($p, $id, $override, 'Typed in error');
            $this->fail('Someone off the sheet is not found.');
        } catch (ModelNotFoundException) {
            // Refused before the write, so the answer (404) and the data agree: the override is still live and unchanged.
            $this->assertDatabaseHas('payroll_overrides', ['id' => $override, 'deleted_at' => null, 'revert_reason' => null, 'version' => 1]);
            $this->assertSame(0, DB::table('audit_log')->where(['entity_id' => $override])->whereIn('event', ['UPDATED', 'DELETED'])->count());
        }
    }

    public function test_a_sheet_costs_the_same_queries_whatever_its_employees_and_days(): void
    {
        // Fares and allowances are read once per period through the Sites read services, never per employee or day.
        $residence = $this->residenceWithFare('40');
        Fare::create(['residence_id' => $residence, 'site_id' => $this->site, 'effective_from' => '2026-10-05', 'amount' => '50']);
        Site::findOrFail($this->site)->allowanceRules()->create(['effective_from' => '2026-01-01', 'mode' => 'share_of_wage', 'value' => '0.25']);
        $p = Period::startingAt(EmployeeType::DayLaborer, '2026-10-01');
        $queries = function () use ($p): int {
            $this->sheet()->sheet($p);   // warms the settings cache
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sheet()->sheet($p);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $first = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $residence]);
        $this->day($first, '2026-10-01');
        $small = $queries();

        $this->day($first, '2026-10-03');
        $this->day($first, '2026-10-05');   // the residence × site fare changed that day
        foreach (['2026-10-01', '2026-10-01', '2026-10-04'] as $from) {
            $id = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $residence]);
            EmployeeFareOverride::create(['employee_id' => $id, 'site_id' => $this->site, 'effective_from' => $from, 'amount' => '25']);
            foreach (['2026-10-03', '2026-10-04', '2026-10-05'] as $date) {
                $this->day($id, $date);
            }
        }

        $this->assertSame($small, $queries());
        // Each day still takes the fare in force that day: 40 + 40 + 50, 3 × 25 twice, then 40 before the override and 2 × 25.
        $this->assertSame(['130.000000', '75.000000', '75.000000', '90.000000'], array_map(fn (array $l) => $l['components']['transport']['final'], $this->sheet()->sheet($p)['lines']));
    }

    private function sheet(): PayrollSheet
    {
        return app(PayrollSheet::class);
    }

    private function engineers(EmployeeType $group = EmployeeType::Engineer): Period
    {
        return Period::startingAt($group, '2026-09-26');
    }

    /** A stored work day at the site, the field shift's times unless $values says otherwise. */
    private function day(string $employeeId, string $date, array $values = []): AttendanceDay
    {
        return AttendanceDay::create([
            'employee_id' => $employeeId, 'date' => $date, 'site_id' => $this->site, 'in_time' => '08:00', 'out_time' => '17:00',
            'source' => 'entered', 'version' => 1, ...$values,
        ]);
    }

    /** A residence whose fare to the site is $amount from 2026-01-01. */
    private function residenceWithFare(string $amount): string
    {
        $residence = (string) Residence::create(['name_ar' => 'Residence-1'])->getKey();
        Fare::create(['residence_id' => $residence, 'site_id' => $this->site, 'effective_from' => '2026-01-01', 'amount' => $amount]);

        return $residence;
    }
}
