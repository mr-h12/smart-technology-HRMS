<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_runs_at_scale_12_and_truncates_past_it(): void
    {
        $this->assertSame('0.300000000000', Money::add('0.1', '0.2'));
        $this->assertSame('-2.000000000000', Money::sub('1', '3'));
        $this->assertSame('82.500000000000', Money::mul('330', '0.25'));
        $this->assertSame('0.625000000000', Money::div('9000', '14400'));
        $this->assertSame('0.333333333333', Money::div('1', '3'));
    }

    public function test_sum_adds_any_number_of_amounts(): void
    {
        $this->assertSame('3.000000000000', Money::sum('1', '2.5', '-0.5'));
        $this->assertSame('0.000000000000', Money::sum());
    }

    public function test_round6_rounds_half_up_symmetrically_for_negatives(): void
    {
        $this->assertSame('0.123457', Money::round6('0.1234565'));
        $this->assertSame('-0.123457', Money::round6('-0.1234565'));
        $this->assertSame('0.123456', Money::round6('0.123456499999'));
        $this->assertSame('-0.123456', Money::round6('-0.123456499999'));
        $this->assertSame('5.000000', Money::round6('5'));
        $this->assertSame('0.000000', Money::round6('-0.0000004'));
    }
}
