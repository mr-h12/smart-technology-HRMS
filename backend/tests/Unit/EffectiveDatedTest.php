<?php

namespace Tests\Unit;

use App\Modules\Employees\Domain\EffectiveDated;
use PHPUnit\Framework\TestCase;

class EffectiveDatedTest extends TestCase
{
    private const ROWS = [
        ['effective_from' => '2026-06-01', 'v' => 'b'],
        ['effective_from' => '2026-01-01', 'v' => 'a'],
        ['effective_from' => '2026-09-01', 'v' => 'c'],
    ];

    public function test_before_first_row_is_null(): void
    {
        $this->assertNull(EffectiveDated::at(self::ROWS, '2025-12-31'));
        $this->assertNull(EffectiveDated::at([], '2026-01-01'));
    }

    public function test_exact_date_wins(): void
    {
        $this->assertSame('b', EffectiveDated::at(self::ROWS, '2026-06-01')['v']);
    }

    public function test_between_rows_takes_the_earlier_one_even_if_unordered(): void
    {
        $this->assertSame('b', EffectiveDated::at(self::ROWS, '2026-08-31')['v']);
        $this->assertSame('c', EffectiveDated::at(self::ROWS, '2030-01-01')['v']);
    }
}
