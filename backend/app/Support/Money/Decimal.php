<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/** Guards for decimal strings; money is never a float. */
final class Decimal
{
    /** Accepts a plain decimal string ("12.5", "-3"); rejects exponents, whitespace, "1.". */
    /** @return numeric-string */
    public static function of(string $value, string $what): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/D', $value) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException("{$what} must be a plain decimal string, not '{$value}'.");
        }

        return $value;
    }

    public static function positive(string $value, string $what): string
    {
        $decimal = self::of($value, $what);

        if (bccomp($decimal, '0', 8) !== 1 || ! is_numeric($value)) {
            throw new InvalidArgumentException("{$what} must be greater than zero, not '{$value}'.");
        }

        return $decimal;
    }
}
