<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

final class Name
{
    /** Trim and collapse internal whitespace runs to one space. */
    public static function normalise(mixed $name): mixed
    {
        return is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : $name;
    }
}
