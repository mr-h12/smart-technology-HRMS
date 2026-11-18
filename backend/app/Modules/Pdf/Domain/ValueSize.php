<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain;

/** Picks the size a form prints its typed values at from how full the fullest value is. */
final class ValueSize
{
    /**
     * @param  array<string, int>  $lengths  characters per value
     * @param  non-empty-array<string, int>  $limits  the printable limit per value
     * @param  non-empty-list<array{0: float, 1: int, 2?: float}>  $steps  share bound, size, optional line spacing
     * @return array{0: float, 1: int, 2?: float} the first step whose bound holds the fullest share, else the last
     */
    public static function byShare(array $lengths, array $limits, array $steps): array
    {
        $fullest = max(array_map(fn (string $key): float => ($lengths[$key] ?? 0) / $limits[$key], array_keys($limits)));

        return array_find($steps, fn (array $step): bool => $fullest <= $step[0]) ?? $steps[array_key_last($steps)];
    }
}
