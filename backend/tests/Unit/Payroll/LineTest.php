<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Domain\Line;
use PHPUnit\Framework\TestCase;

class LineTest extends TestCase
{
    public function test_negative_net_flag_follows_the_net(): void
    {
        // Checked again after the net changes (an override): there once while the net is below 0, gone once it is not.
        $negative = new Line(['net' => '-0.010000'], [], ['missing', 'negative_net']);
        $this->assertSame(['missing', 'negative_net'], $negative->withNegativeNetFlag()->withNegativeNetFlag()->flags);

        $settled = new Line(['net' => '0.000000'], [], ['negative_net', 'missing']);
        $this->assertSame(['missing'], $settled->withNegativeNetFlag()->flags);
    }

    public function test_net_is_each_component_by_its_sign(): void
    {
        // Pay adds, deductions subtract; insured basic, incentives, the company's insurance share and a net already there count nothing.
        $components = ['salary' => '9000', 'bonus' => '100', 'lateness' => '50', 'income_tax' => '400', 'insured_basic' => '5000', 'incentives' => '4000', 'insurance_company' => '700', 'net' => '1'];

        $this->assertSame('8650.000000000000', Line::net($components));
        $this->assertSame('8650.000000', Line::computed($components, [])->components['net']);
    }
}
