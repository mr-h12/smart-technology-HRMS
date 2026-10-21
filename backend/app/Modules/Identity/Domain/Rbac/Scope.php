<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/** The reach of a permission: `own` (the user's assigned employees/sites) or `all`. */
enum Scope: string
{
    case Own = 'own';
    case All = 'all';

    public function includes(self $other): bool
    {
        return $this === $other || $this === self::All;
    }
}
