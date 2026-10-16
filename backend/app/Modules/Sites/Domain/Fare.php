<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

use InvalidArgumentException;

final class Fare
{
    /** The employee override wins, else the residence x site default, else 0. */
    /** @param numeric-string|null $override
     *  @param numeric-string|null $default */
    public static function resolve(?string $override, ?string $default): string
    {
        return bcadd($override ?? $default ?? '0', '0', 6);
    }

    /** @param numeric-string $fare */
    public static function forDay(string $fare, string $portion): string
    {
        return match ($portion) {
            'full' => bcadd($fare, '0', 6),
            'half' => bcdiv($fare, '2', 6),
            'none' => '0.000000',
            default => throw new InvalidArgumentException("Unknown day portion '{$portion}'."),
        };
    }
}
