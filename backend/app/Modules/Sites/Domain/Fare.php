<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

use InvalidArgumentException;

final class Fare
{
    /** The employee override wins, else the residence x site default, else 0. */
    public static function resolve(?string $override, ?string $default): string
    {
        return $override ?? $default ?? '0';
    }

    /** @param numeric-string $fare */
    public static function forDay(string $fare, string $portion): string
    {
        return match ($portion) {
            'full' => $fare,
            'half' => bcdiv($fare, '2', 6),
            'none' => '0',
            default => throw new InvalidArgumentException("Unknown day portion '{$portion}'."),
        };
    }
}
