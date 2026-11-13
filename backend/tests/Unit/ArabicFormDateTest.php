<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use PHPUnit\Framework\TestCase;

final class ArabicFormDateTest extends TestCase
{
    public function test_pdf_dates_use_arabic_weekday_and_day_month_year(): void
    {
        self::assertSame(['weekday' => 'الخميس', 'date' => '05/11/2026'], ArabicFormDate::format('2026-11-05'));
    }

    public function test_negative_balance_keeps_its_sign_and_magnitude(): void
    {
        self::assertSame('-1 يوم', ArabicServiceDuration::days(-1));
        self::assertSame('-4 أيام', ArabicServiceDuration::days(-4));
        self::assertSame('-20 يومًا', ArabicServiceDuration::days(-20));
        self::assertSame('20 يومًا', ArabicServiceDuration::days(20));
    }
}
