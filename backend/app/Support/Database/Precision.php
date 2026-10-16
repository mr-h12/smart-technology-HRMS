<?php

declare(strict_types=1);

namespace App\Support\Database;

/** Numeric precisions in one place so column scale and model cast scale cannot drift. */
final class Precision
{
    public const MONEY_TOTAL = 18;

    public const MONEY_SCALE = 6;

    public const PERCENT_TOTAL = 6;

    public const PERCENT_SCALE = 3;

    public const QUANTITY_TOTAL = 14;

    public const QUANTITY_SCALE = 4;

    public const CAST_MONEY = 'decimal:'.self::MONEY_SCALE;

    public const CAST_PERCENT = 'decimal:'.self::PERCENT_SCALE;

    public const CAST_QUANTITY = 'decimal:'.self::QUANTITY_SCALE;
}
