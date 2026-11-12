<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Sites\Application\AllowanceRules;
use App\Modules\Sites\Domain\AllowanceMode;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Modules\Sites\Infrastructure\Eloquent\SiteAllowanceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The read services the Payroll module builds on: dated pay rows on the roster, and site allowance rules for a period. */
class PayrollReadServicesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-05 10:00');
    }

    public function test_roster_row_lists_every_pay_row_by_date(): void
    {
        $id = $this->employee('engineer', '2026-01-01');
        app(EmployeeService::class)->addPayChange($id, ['effective_from' => '2026-09-01', 'salary' => '10000']);
        app(EmployeeService::class)->addPayChange($id, ['effective_from' => '2026-06-01', 'raise' => '500', 'insured_basic' => '5000']);

        $pays = app(EmployeeRoster::class)->find($id)['pays'];

        $this->assertSame([
            ['effective_from' => '2026-01-01', 'salary' => '9000.000000', 'raise' => '0.000000', 'insured_basic' => null, 'daily_wage' => null],
            ['effective_from' => '2026-06-01', 'salary' => '9000.000000', 'raise' => '500.000000', 'insured_basic' => '5000.000000', 'daily_wage' => null],
            ['effective_from' => '2026-09-01', 'salary' => '10000.000000', 'raise' => '0.000000', 'insured_basic' => null, 'daily_wage' => null],
        ], $pays);
        $this->assertSame('500.000000', EffectiveDated::at($pays, '2026-08-31')['raise']);
        $this->assertSame('10000.000000', EffectiveDated::at($pays, '2026-09-01')['salary']);
    }

    public function test_roster_row_of_a_day_laborer_carries_the_daily_wage(): void
    {
        $id = $this->employee('day_laborer', '2026-01-01');

        $this->assertSame(
            [['effective_from' => '2026-01-01', 'salary' => null, 'raise' => '0.000000', 'insured_basic' => null, 'daily_wage' => '330.000000']],
            app(EmployeeRoster::class)->find($id)['pays'],
        );
    }

    public function test_in_force_returns_the_rule_on_or_before_each_day_of_the_period_in_one_query(): void
    {
        [$a, $b, $c, $none] = [$this->workSite('Site-A'), $this->workSite('Site-B'), $this->workSite('Site-C'), $this->workSite('Site-D')];
        $this->rule($a, '2026-01-01', 'fixed', '50');
        $this->rule($a, '2026-06-01', 'share_of_wage', '0.25');
        $this->rule($a, '2026-09-01', 'fixed', '99')->delete();
        $this->rule($a, '2026-10-05', 'fixed', '60');
        $this->rule($a, '2026-12-01', 'none', '0');
        $this->rule($b, '2026-11-01', 'fixed', '70');
        $this->rule($c, '2026-09-26', 'share_of_wage', '0.5');

        DB::enableQueryLog();
        $rules = app(AllowanceRules::class)->inForce([$a, $b, $c, $none], '2026-09-26', '2026-10-25');
        $this->assertCount(1, DB::getQueryLog());

        $this->assertSame([
            $a => [
                ['effective_from' => '2026-06-01', 'mode' => AllowanceMode::ShareOfWage, 'value' => '0.250000'],
                ['effective_from' => '2026-10-05', 'mode' => AllowanceMode::Fixed, 'value' => '60.000000'],
            ],
            $b => [],
            $c => [['effective_from' => '2026-09-26', 'mode' => AllowanceMode::ShareOfWage, 'value' => '0.500000']],
            $none => [],
        ], $rules);
        $this->assertSame('0.250000', EffectiveDated::at($rules[$a], '2026-10-04')['value']);
        $this->assertSame('60.000000', EffectiveDated::at($rules[$a], '2026-10-05')['value']);
        $this->assertNull(EffectiveDated::at($rules[$b], '2026-10-25'));
    }

    private function rule(string $siteId, string $from, string $mode, string $value): SiteAllowanceRule
    {
        return Site::findOrFail($siteId)->allowanceRules()->create(['effective_from' => $from, 'mode' => $mode, 'value' => $value]);
    }
}
