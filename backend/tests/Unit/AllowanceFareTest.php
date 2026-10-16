<?php

namespace Tests\Unit;

use App\Modules\Sites\Domain\Allowance;
use App\Modules\Sites\Domain\Fare;
use PHPUnit\Framework\TestCase;

class AllowanceFareTest extends TestCase
{
    public function test_allowance_modes(): void
    {
        $this->assertSame('82.500000', Allowance::for('share_of_wage', '0.25', '330'));
        $this->assertSame('660.000000', Allowance::for('share_of_wage', '2', '330'));
        $this->assertSame('50.000000', Allowance::for('fixed', '50', '330'));
        $this->assertSame('0.000000', Allowance::for('none', '0', '330'));
    }

    public function test_share_of_wage_is_truncated_not_rounded_at_scale_6(): void
    {
        $this->assertSame('0.333333', Allowance::for('share_of_wage', '0.3333333', '1'));
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Allowance::for('bogus', '1', '1');
    }

    public function test_fare_resolve_override_wins_then_default_then_zero(): void
    {
        $this->assertSame('15.000000', Fare::resolve('15', '10'));
        $this->assertSame('0.000000', Fare::resolve('0', '10'));
        $this->assertSame('10.000000', Fare::resolve(null, '10'));
        $this->assertSame('0.000000', Fare::resolve(null, null));
    }

    public function test_fare_for_day(): void
    {
        $this->assertSame('10.000000', Fare::forDay('10', 'full'));
        $this->assertSame('5.000000', Fare::forDay('10', 'half'));
        $this->assertSame('0.000000', Fare::forDay('10', 'none'));
    }
}
