<?php

namespace Tests\Feature;

use App\Modules\Payroll\Application\PayrollSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * The payroll API (design 2026-11-09, "API and screen") over PayrollSheet: HR reads and writes, the CEO reads, site managers get
 * nothing. Field shift 08:00–17:00 (the seeded setting), S = 9000.
 */
class PayrollApiTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** Each type's sheet for the period that holds October 2026. */
    private const PERIOD = [
        'office' => '/api/v1/payroll/office/2026-10-01',
        'engineer' => '/api/v1/payroll/engineer/2026-09-26',
        'permanent_worker' => '/api/v1/payroll/permanent_worker/2026-09-26',
        'day_laborer' => '/api/v1/payroll/day_laborer/2026-10-01',
    ];

    /** The entry fields each type takes besides `note` (controller ruling: office has negligence, never a deduction). */
    private const USED = [
        'office' => ['bonus', 'negligence', 'lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes', 'advances', 'insurance_employee', 'insurance_company', 'income_tax'],
        'engineer' => ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
        'permanent_worker' => ['deduction', 'deduction_reason', 'insurance_employee', 'insurance_company', 'income_tax'],
        'day_laborer' => ['bonus', 'deduction', 'deduction_reason'],
    ];

    /** A valid value for every entry field. */
    private const VALUES = [
        'bonus' => '10', 'deduction' => '30', 'negligence' => '5', 'advances' => '100', 'insurance_employee' => '50',
        'insurance_company' => '70', 'income_tax' => '20', 'lateness_exemption_minutes' => 10, 'travel_days' => 1, 'tower_days' => 2,
        'ot_minutes' => 60, 'deduction_reason' => 'Late report', 'note' => 'Checked',
    ];

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-05 10:00');
        $this->signIn('hr');
        $this->leaveTypes();
        $this->site = $this->workSite();
    }

    public function test_hr_reads_the_sheet_and_an_employee_line(): void
    {
        $id = $this->employee('engineer');

        $sheet = $this->getJson(self::PERIOD['engineer'])->assertOk()
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.lines.0.employee.id', $id)
            ->assertJsonPath('data.lines.0.employee.name_ar', 'Engineer-1')
            ->assertJsonPath('data.lines.0.components.salary', ['computed' => '9000.000000', 'override' => null, 'final' => '9000.000000'])
            ->assertJsonPath('data.lines.0.entry.version', null)
            ->assertJsonPath('data.totals.net', '9000.000000')
            ->json('data');
        $this->assertSame(['lines', 'totals', 'locked'], array_keys($sheet));
        $this->assertArrayNotHasKey('days', $sheet['lines'][0]);

        $this->getJson(self::PERIOD['engineer']."/employees/{$id}")->assertOk()
            ->assertJsonPath('data.employee.id', $id)
            ->assertJsonCount(30, 'data.days')   // 26 Sep → 25 Oct
            ->assertJsonPath('data.days.0.date', '2026-09-26');
    }

    public function test_hr_saves_an_entry_then_overrides_and_reverts_a_component(): void
    {
        $id = $this->employee('engineer');
        $url = self::PERIOD['engineer']."/employees/{$id}";

        $this->putJson("{$url}/entry", ['version' => null, 'deduction' => '100', 'deduction_reason' => 'Late report', 'insurance_employee' => '50.5', 'note' => 'Checked'])
            ->assertOk()
            ->assertJsonPath('data.entry.version', 1)
            ->assertJsonPath('data.entry.deduction', '100.000000')
            ->assertJsonPath('data.entry.note', 'Checked')
            ->assertJsonPath('data.components.net.final', '8849.500000');   // 9000 − 100 − 50.5

        // Only the net may go below 0; it is the line's result, every other component a magnitude its sign adds or deducts.
        $override = $this->postJson("{$url}/overrides", ['component' => 'net', 'amount' => '-25.5', 'reason' => 'Settled by hand'])
            ->assertCreated()
            ->assertJsonPath('data.components.net.final', '-25.500000')
            ->assertJsonPath('data.components.net.override.reason', 'Settled by hand')
            ->json('data.components.net.override.id');
        $this->postJson("{$url}/overrides", ['component' => 'net', 'amount' => '1', 'reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');   // one live override per component

        $this->deleteJson("{$url}/overrides/{$override}", ['reason' => 'Typed in error'])->assertOk()
            ->assertJsonPath('data.components.net', ['computed' => '8849.500000', 'override' => null, 'final' => '8849.500000']);
        $this->deleteJson("{$url}/overrides/{$override}", ['reason' => 'Again'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_site_manager_gets_403_everywhere(): void
    {
        $id = $this->employee('engineer');
        $override = $this->overrideOf($id);
        $this->actingAs($this->managerOf($this->site), 'api');

        foreach ($this->everyRoute($id, $override) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        }
    }

    public function test_ceo_reads_but_cannot_write(): void
    {
        $id = $this->employee('engineer');
        $override = $this->overrideOf($id);
        $this->actingAs($this->makeUser('ceo'), 'api');

        $routes = $this->everyRoute($id, $override);
        $this->json(...$routes[0])->assertOk();
        $this->json(...$routes[1])->assertOk()->assertJsonPath('data.components.overtime.override.id', $override);
        foreach (array_slice($routes, 2) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        }
        $this->assertNull($this->getJson(self::PERIOD['engineer']."/employees/{$id}")->json('data.entry.version'));
    }

    public function test_entry_fields_a_type_does_not_use_are_refused(): void
    {
        $laborer = $this->employee('day_laborer');
        $engineer = $this->employee('engineer');

        $this->refused($this->withHeader('Accept-Language', 'ar')->putJson(self::PERIOD['day_laborer']."/employees/{$laborer}/entry", ['insurance_employee' => '100']), 'insurance_employee')
            ->assertJsonPath('error.details.0.message', 'حقل التأمينات (حصة الموظف) لا يُستخدم لنوع الموظف عامل باليومية.');
        $this->refused($this->withHeader('Accept-Language', 'en')->putJson(self::PERIOD['engineer']."/employees/{$engineer}/entry", ['travel_days' => 2]), 'travel_days')
            ->assertJsonPath('error.details.0.message', 'Travel days is not used for Engineer.');
        $this->assertNull($this->getJson(self::PERIOD['engineer']."/employees/{$engineer}")->json('data.entry.version'));
    }

    public function test_each_type_takes_exactly_its_entry_fields(): void
    {
        foreach (self::USED as $type => $used) {
            $id = $this->employee($type);
            $url = self::PERIOD[$type]."/employees/{$id}/entry";
            $body = array_intersect_key(self::VALUES, array_flip([...$used, 'note']));

            $line = $this->putJson($url, ['version' => null, ...$body])->assertOk()->json('data');
            foreach ($body as $field => $value) {
                $this->assertSame(is_string($value) && in_array($field, PayrollSheet::AMOUNTS, true) ? "{$value}.000000" : $value, $line['entry'][$field], "{$type} {$field}");
            }
            // Every amount the type takes reaches its component of the line.
            foreach (array_intersect($used, PayrollSheet::AMOUNTS) as $field) {
                $this->assertSame(self::VALUES[$field].'.000000', $line['components'][$field]['final'], "{$type} {$field}");
            }

            foreach (array_diff(PayrollSheet::FIELDS, $used, ['note']) as $field) {
                $this->refused($this->putJson($url, ['version' => 1, $field => self::VALUES[$field]]), $field);
            }
            // Blank is cleared: null for a field the type does not use is no value, so it passes.
            $this->putJson($url, ['version' => 1, ...array_fill_keys(PayrollSheet::FIELDS, null)])->assertOk()->assertJsonPath('data.entry.version', 2);
        }
    }

    public function test_an_unknown_entry_key_is_refused_not_ignored(): void
    {
        $id = $this->employee('engineer');

        $this->refused($this->putJson(self::PERIOD['engineer']."/employees/{$id}/entry", ['salary' => '1', 'note' => 'x']), 'salary');
    }

    public function test_a_deduction_needs_a_reason(): void
    {
        $id = $this->employee('engineer');
        $url = self::PERIOD['engineer']."/employees/{$id}/entry";

        $this->refused($this->putJson($url, ['deduction' => '0.5']), 'deduction_reason');
        $this->refused($this->putJson($url, ['deduction' => '100', 'deduction_reason' => '   ']), 'deduction_reason');
        $this->putJson($url, ['deduction' => '0.000'])->assertOk()->assertJsonPath('data.entry.deduction', '0.000000');   // a zero deduction needs none
    }

    public function test_entry_amounts_are_decimal_strings_and_counts_whole_numbers(): void
    {
        $id = $this->employee('office');

        $this->refused($this->putJson(self::PERIOD['office']."/employees/{$id}/entry", [
            'bonus' => 100,                  // a JSON number: money travels as a decimal string
            'negligence' => '-1',
            'advances' => '1.1234567',       // 7 decimals
            'income_tax' => 'abc',
            'ot_minutes' => -1,
            'travel_days' => 1.5,
            'tower_days' => '2',             // a string: counts are JSON integers
            'note' => str_repeat('x', 501),
        ]), 'bonus', 'negligence', 'advances', 'income_tax', 'ot_minutes', 'travel_days', 'tower_days', 'note');
    }

    public function test_stale_entry_version_conflicts(): void
    {
        // Review Focus 3: two HR users save over the same version; the second gets 409 and overwrites nothing.
        $id = $this->employee('engineer');
        $url = self::PERIOD['engineer']."/employees/{$id}";
        $this->putJson("{$url}/entry", ['version' => null, 'deduction' => '100', 'deduction_reason' => 'Late report'])->assertOk();

        $this->putJson("{$url}/entry", ['version' => 1, 'deduction' => '200', 'deduction_reason' => 'First user'])->assertOk()->assertJsonPath('data.entry.version', 2);
        $this->withHeader('Accept-Language', 'ar')->putJson("{$url}/entry", ['version' => 1, 'deduction' => '300', 'deduction_reason' => 'Second user'])
            ->assertStatus(409)->assertJsonPath('error', ['code' => 'concurrency_conflict', 'message' => self::CONFLICT_MESSAGE['ar']]);
        $this->putJson("{$url}/entry", ['version' => null, 'note' => 'A second first save'])->assertStatus(409);

        $this->getJson($url)->assertJsonPath('data.entry.deduction', '200.000000')->assertJsonPath('data.entry.deduction_reason', 'First user')
            ->assertJsonPath('data.entry.version', 2);
    }

    public function test_override_needs_a_reason(): void
    {
        $id = $this->employee('engineer');
        $url = self::PERIOD['engineer']."/employees/{$id}/overrides";

        $this->refused($this->postJson($url, ['component' => 'overtime', 'amount' => '100']), 'reason');
        $this->refused($this->postJson($url, ['component' => 'overtime', 'amount' => '100', 'reason' => '   ']), 'reason');

        $override = $this->overrideOf($id);
        $this->refused($this->deleteJson("{$url}/{$override}"), 'reason');
        $this->refused($this->deleteJson("{$url}/{$override}", ['reason' => ' ']), 'reason');
        $this->getJson(self::PERIOD['engineer']."/employees/{$id}")->assertJsonPath('data.components.overtime.override.id', $override);
    }

    public function test_override_takes_a_component_of_the_line_and_a_decimal_string(): void
    {
        $id = $this->employee('engineer');
        $url = self::PERIOD['engineer']."/employees/{$id}/overrides";

        $this->refused($this->postJson($url, ['component' => 'pension', 'amount' => '1', 'reason' => 'x']), 'component');
        $this->refused($this->withHeader('Accept-Language', 'ar')->postJson($url, ['component' => 'travel', 'amount' => '1', 'reason' => 'x']), 'component')   // office only
            ->assertJsonPath('error.details.0.message', 'قيمة حقل البند غير موجودة في قائمة القيم المسموح بها.');
        $this->refused($this->postJson($url, ['component' => 'overtime', 'amount' => '-1', 'reason' => 'x']), 'amount');
        $this->refused($this->postJson($url, ['component' => 'overtime', 'amount' => 100, 'reason' => 'x']), 'amount');
        $this->refused($this->postJson($url, ['component' => 'net', 'amount' => '-1.1234567', 'reason' => 'x']), 'amount');
        $this->getJson(self::PERIOD['engineer']."/employees/{$id}")->assertJsonPath('data.flags', ['missing']);   // nothing written
    }

    public function test_bad_period_start_is_422(): void
    {
        $id = $this->employee('engineer');

        foreach (['engineer/2026-10-01', 'engineer/2026-02-30', 'engineer/26-09-2026', 'office/2026-09-26', 'day_laborer/2026-10-02'] as $path) {
            $this->refused($this->getJson("/api/v1/payroll/{$path}"), 'period_start');
        }
        $this->refused($this->getJson('/api/v1/payroll/manager/2026-10-01'), 'group');
        $this->refused($this->putJson("/api/v1/payroll/engineer/2026-10-01/employees/{$id}/entry", ['note' => 'x']), 'period_start');
        $this->refused($this->postJson("/api/v1/payroll/engineer/2026-10-01/employees/{$id}/overrides", ['component' => 'net', 'amount' => '1', 'reason' => 'x']), 'period_start');
    }

    public function test_an_employee_off_the_sheet_is_404(): void
    {
        $laborer = $this->employee('day_laborer');   // not on the engineers' sheet
        $engineer = $this->employee('engineer');
        $other = $this->employee('engineer');
        $override = $this->overrideOf($other);
        $unknown = '01900000-0000-7000-8000-000000000000';

        foreach ([$laborer, $unknown, 'not-a-uuid'] as $id) {
            $url = self::PERIOD['engineer']."/employees/{$id}";
            $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->putJson("{$url}/entry", ['note' => 'x'])->assertNotFound();
            $this->postJson("{$url}/overrides", ['component' => 'net', 'amount' => '1', 'reason' => 'x'])->assertNotFound();
        }
        // An override is reached only through its own employee, group and period.
        $this->deleteJson(self::PERIOD['engineer']."/employees/{$engineer}/overrides/{$override}", ['reason' => 'x'])->assertNotFound();
        $this->deleteJson(self::PERIOD['permanent_worker']."/employees/{$other}/overrides/{$override}", ['reason' => 'x'])->assertNotFound();
        $this->deleteJson('/api/v1/payroll/engineer/2026-10-26'."/employees/{$other}/overrides/{$override}", ['reason' => 'x'])->assertNotFound();
        $this->deleteJson(self::PERIOD['engineer']."/employees/{$other}/overrides/not-a-uuid", ['reason' => 'x'])->assertNotFound();
        $this->getJson(self::PERIOD['engineer']."/employees/{$other}")->assertJsonPath('data.components.overtime.override.id', $override);
    }

    /** An overtime override of 100 on the employee's engineer line, made by HR through the API. */
    private function overrideOf(string $id): string
    {
        return $this->postJson(self::PERIOD['engineer']."/employees/{$id}/overrides", ['component' => 'overtime', 'amount' => '100', 'reason' => 'Approved by HR'])
            ->assertCreated()->json('data.components.overtime.override.id');
    }

    /** @return list<array{string, string, array<string, mixed>}> method, uri, body: the two reads, then the three writes */
    private function everyRoute(string $id, string $override): array
    {
        $url = self::PERIOD['engineer']."/employees/{$id}";

        return [
            ['GET', self::PERIOD['engineer'], []],
            ['GET', $url, []],
            ['PUT', "{$url}/entry", ['version' => null, 'note' => 'x']],
            ['POST', "{$url}/overrides", ['component' => 'net', 'amount' => '1', 'reason' => 'x']],
            ['DELETE', "{$url}/overrides/{$override}", ['reason' => 'x']],
        ];
    }

    /** A 422 whose field errors are exactly these fields. */
    private function refused(TestResponse $response, string ...$fields): TestResponse
    {
        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertEqualsCanonicalizing($fields, array_values(array_unique(array_column($response->json('error.details'), 'field'))));

        return $response;
    }
}
