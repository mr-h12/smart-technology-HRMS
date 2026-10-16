<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

use InvalidArgumentException;

/** Daily site allowance from a rule. Scale 6, truncated (never rounded). */
final class Allowance
{
    /** @param numeric-string $value  multiplier for share_of_wage, amount for fixed
     * @param  numeric-string  $dailyWage */
    public static function for(string $mode, string $value, string $dailyWage): string
    {
        return match (AllowanceMode::tryFrom($mode)) {
            AllowanceMode::None => '0.000000',
            AllowanceMode::ShareOfWage => bcmul($dailyWage, $value, 6),
            AllowanceMode::Fixed => bcadd($value, '0', 6),
            null => throw new InvalidArgumentException("Unknown allowance mode '{$mode}'."),
        };
    }
}
