<?php

declare(strict_types=1);

namespace App\Support\Localization;

use Illuminate\Support\Carbon;

final class ArabicFormDate
{
    /** @return array{weekday: string, date: string} */
    public static function format(string $value): array
    {
        $date = Carbon::parse($value, 'Africa/Cairo');
        $date->locale('ar');

        return ['weekday' => $date->isoFormat('dddd'), 'date' => $date->format('d/m/Y')];
    }

    /**
     * The weekday and «d / m / Y». Typed in that order in an RTL line, the year stands at the left. Never wrap it in one <bdi>.
     *
     * @return array{weekday: string, date: string}
     */
    public static function spaced(string $date): array
    {
        $formatted = self::format($date);

        return ['weekday' => $formatted['weekday'], 'date' => str_replace('/', ' / ', $formatted['date'])];
    }
}
