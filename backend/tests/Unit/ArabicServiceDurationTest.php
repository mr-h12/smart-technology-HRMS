<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Localization\ArabicServiceDuration;
use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArabicServiceDurationTest extends TestCase
{
    public static function durations(): iterable
    {
        yield 'no years' => ['P0Y1M', 'شهر واحد'];
        yield 'no months' => ['P1Y0M', 'سنة واحدة'];
        yield 'no days' => ['P1M0D', 'شهر واحد'];
        yield 'all zero' => ['P0D', ''];

        foreach ([
            'Y' => ['سنة واحدة', 'سنتان', '3 سنوات', '10 سنوات', '11 سنة'],
            'M' => ['شهر واحد', 'شهران', '3 أشهر', '10 أشهر', '11 شهرًا'],
            'D' => ['يوم واحد', 'يومان', '3 أيام', '10 أيام', '11 يومًا'],
        ] as $unit => $expected) {
            foreach (array_combine([1, 2, 3, 10, 11], $expected) as $count => $text) {
                yield "{$count}{$unit}" => ["P{$count}{$unit}", $text];
            }
        }

        yield 'combined' => ['P4Y10M11D', '4 سنوات و10 أشهر و11 يومًا'];
    }

    #[DataProvider('durations')]
    public function test_arabic_number_agreement(string $interval, string $expected): void
    {
        self::assertSame($expected, ArabicServiceDuration::format(new DateInterval($interval)));
    }
}
