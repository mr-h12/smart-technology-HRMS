<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

/** The value in force on a date is the row with the greatest effective_from <= date. */
final class EffectiveDated
{
    /**
     * @param  array<array<string, mixed>>  $rows  each with a Y-m-d effective_from
     * @return array<string, mixed>|null
     */
    public static function at(array $rows, string $date): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($row['effective_from'] <= $date && ($best === null || $row['effective_from'] > $best['effective_from'])) {
                $best = $row;
            }
        }

        return $best;
    }
}
