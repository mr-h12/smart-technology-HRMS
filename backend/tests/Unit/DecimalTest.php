<?php

namespace Tests\Unit;

use App\Support\Money\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_accepts_plain_decimals(): void
    {
        $this->assertSame('12.5', Decimal::of('12.5', 'x'));
        $this->assertSame('-3', Decimal::of('-3', 'x'));
    }

    #[DataProvider('bad')]
    public function test_rejects_non_plain_decimals(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of($value, 'x');
    }

    /** @return array<string, array{string}> */
    public static function bad(): array
    {
        return ['exponent' => ['1e5'], 'leading space' => [' 1'], 'trailing dot' => ['1.']];
    }

    public function test_positive_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::positive('0', 'x');
    }
}
