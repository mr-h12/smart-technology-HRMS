<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Arabic-Indic (٠–٩) and Persian (۰–۹) digits, and the Arabic decimal separator «٫», read as ASCII, as a phone's Arabic keyboard
 * types them. Text only: the result stays a string, so phone numbers and national IDs keep their leading zeros and money never
 * passes through a float. A value that is still malformed after this is refused by its own validation rule.
 */
final class Digits
{
    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.',
    ];

    public static function ascii(string $value): string
    {
        return strtr($value, self::MAP);
    }
}
