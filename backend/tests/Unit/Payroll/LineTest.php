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
}
