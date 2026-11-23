<?php

declare(strict_types=1);

namespace App\Support\Localization;

use DateInterval;

final class ArabicServiceDuration
{
    /** 3 to 10 in words before a feminine noun (سنوات); a masculine noun (أشهر، أيام) adds «ة». */
    private const NUMBERS = [3 => 'ثلاث', 4 => 'أربع', 5 => 'خمس', 6 => 'ست', 7 => 'سبع', 8 => 'ثماني', 9 => 'تسع', 10 => 'عشر'];

    /** Whole years and months in words after «تجاوزت» (the accusative); the days only under a month, at least one. */
    public static function format(DateInterval $duration): string
    {
        $parts = array_filter([
            self::words($duration->y, 'سنة واحدة', 'سنتين', '', 'سنوات', 'سنة'),
            $duration->m === 11 ? 'أحد عشر شهرًا' : self::words($duration->m, 'شهرًا واحدًا', 'شهرين', 'ة', 'أشهر', 'شهرًا'),
        ]);

        return $parts === [] ? self::words(max($duration->d, 1), 'يومًا واحدًا', 'يومين', 'ة', 'أيام', 'يومًا') : implode(' و', $parts);
    }

    private static function words(int $count, string $one, string $two, string $gender, string $plural, string $many): string
    {
        return match (true) {
            $count === 0 => '',
            $count === 1 => $one,
            $count === 2 => $two,
            isset(self::NUMBERS[$count]) => self::NUMBERS[$count].$gender.' '.$plural,
            default => "{$count} {$many}",
        };
    }

    public static function days(int $count): string
    {
        return $count < 0
            ? $count.' '.match (true) {
                $count >= -2 => 'يوم',
                $count >= -10 => 'أيام',
                default => 'يومًا',
            }
        : self::part($count, 'يوم واحد', 'يومان', 'أيام', 'يومًا');
    }

    public static function hoursAndMinutes(int $minutes): string
    {
        return implode(' و', array_filter([
            self::part(intdiv($minutes, 60), 'ساعة واحدة', 'ساعتان', 'ساعات', 'ساعة'),
            self::part($minutes % 60, 'دقيقة واحدة', 'دقيقتان', 'دقائق', 'دقيقة'),
        ]));
    }

    private static function part(int $count, string $singular, string $dual, string $plural, string $accusative): string
    {
        return match (true) {
            $count === 0 => '',
            $count === 1 => $singular,
            $count === 2 => $dual,
            $count <= 10 => "{$count} {$plural}",
            default => "{$count} {$accusative}",
        };
    }
}
