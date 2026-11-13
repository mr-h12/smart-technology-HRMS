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
}
