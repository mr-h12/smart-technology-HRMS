<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Engine money math on bcmath decimal strings: scale 12 inside the engine, each returned amount rounded to 6 (the NUMERIC(18,6) scale).
 * Lives in Payroll's Domain (Domain may not use App\Support); it moves to App\Support\Money when a second module needs it (design, "Shape").
 */
final class Money
{
    private const SCALE = 12;

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    public static function div(string $a, string $b): string
    {
        return bcdiv($a, $b, self::SCALE);
    }

    /**
     * @param  numeric-string  ...$xs
     * @return numeric-string
     */
    public static function sum(string ...$xs): string
    {
        return array_reduce($xs, self::add(...), self::add('0', '0'));
    }

    /**
     * Half-up to 6 decimals, away from zero for negatives (bcround's default mode).
     *
     * @param  numeric-string  $a
     * @return numeric-string
     */
    public static function round6(string $a): string
    {
        return bcround($a, 6);
    }
}
