<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Localization\ArabicServiceDuration;
use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArabicServiceDurationTest extends TestCase
{
    /** Spec section 4: the words that follow «تجاوزت» on the certificate. */
    public static function durations(): iterable
    {
        yield '1 year' => ['P1Y', 'سنة واحدة'];
        yield '2 years' => ['P2Y', 'سنتين'];
        yield '3 years' => ['P3Y', 'ثلاث سنوات'];
        yield '4 years' => ['P4Y', 'أربع سنوات'];
        yield '5 years' => ['P5Y', 'خمس سنوات'];
        yield '6 years' => ['P6Y', 'ست سنوات'];
        yield '7 years' => ['P7Y', 'سبع سنوات'];
        yield '8 years' => ['P8Y', 'ثماني سنوات'];
        yield '9 years' => ['P9Y', 'تسع سنوات'];
        yield '10 years' => ['P10Y', 'عشر سنوات'];
        yield '11 years' => ['P11Y', '11 سنة'];
        yield '16 years' => ['P16Y', '16 سنة'];

        yield '1 month' => ['P1M', 'شهرًا واحدًا'];
        yield '2 months' => ['P2M', 'شهرين'];
        yield '3 months' => ['P3M', 'ثلاثة أشهر'];
        yield '4 months' => ['P4M', 'أربعة أشهر'];
        yield '5 months' => ['P5M', 'خمسة أشهر'];
        yield '6 months' => ['P6M', 'ستة أشهر'];
        yield '7 months' => ['P7M', 'سبعة أشهر'];
        yield '8 months' => ['P8M', 'ثمانية أشهر'];
        yield '9 months' => ['P9M', 'تسعة أشهر'];
        yield '10 months' => ['P10M', 'عشرة أشهر'];
        yield '11 months' => ['P11M', 'أحد عشر شهرًا'];

        yield 'the approved female, 2022-01-01 to 2026-11-18' => ['P4Y10M17D', 'أربع سنوات وعشرة أشهر'];
        yield 'the approved long copies, 2010-03-01 to 2026-11-18' => ['P16Y8M17D', '16 سنة وثمانية أشهر'];
        yield 'the approved archived copies, 2022-01-01 to 2026-10-18' => ['P4Y9M17D', 'أربع سنوات وتسعة أشهر'];
        yield 'one year and one month' => ['P1Y1M', 'سنة واحدة وشهرًا واحدًا'];
        yield 'two years and two months' => ['P2Y2M', 'سنتين وشهرين'];
        yield 'eleven years and eleven months' => ['P11Y11M', '11 سنة وأحد عشر شهرًا'];
        yield 'a year and days drops the days' => ['P1Y0M20D', 'سنة واحدة'];
        yield 'a month and days drops the days' => ['P1M20D', 'شهرًا واحدًا'];

        yield '0 days' => ['P0D', 'يومًا واحدًا'];
        yield '1 day' => ['P1D', 'يومًا واحدًا'];
        yield '2 days' => ['P2D', 'يومين'];
        yield '3 days' => ['P3D', 'ثلاثة أيام'];
        yield '4 days' => ['P4D', 'أربعة أيام'];
        yield '5 days' => ['P5D', 'خمسة أيام'];
        yield '6 days' => ['P6D', 'ستة أيام'];
        yield '7 days' => ['P7D', 'سبعة أيام'];
        yield '8 days' => ['P8D', 'ثمانية أيام'];
        yield '9 days' => ['P9D', 'تسعة أيام'];
        yield '10 days' => ['P10D', 'عشرة أيام'];
        yield '11 days' => ['P11D', '11 يومًا'];
        yield '30 days' => ['P30D', '30 يومًا'];
    }

    #[DataProvider('durations')]
    public function test_format_years_months_and_days(string $interval, string $expected): void
    {
        self::assertSame($expected, ArabicServiceDuration::format(new DateInterval($interval)));
    }
}
