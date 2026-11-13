<?php

declare(strict_types=1);

namespace App\Support\Localization;

use DateInterval;

final class ArabicServiceDuration
{
    public static function format(DateInterval $duration): string
    {
        $parts = [
            self::part($duration->y, 'سنة واحدة', 'سنتان', 'سنوات', 'سنة'),
            self::part($duration->m, 'شهر واحد', 'شهران', 'أشهر', 'شهرًا'),
            self::days($duration->d),
        ];

        return implode(' و', array_filter($parts));
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
