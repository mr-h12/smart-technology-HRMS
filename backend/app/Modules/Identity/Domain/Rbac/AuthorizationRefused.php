<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

use RuntimeException;

/** Rendered as 403 `permission_denied` in bootstrap/app.php. */
final class AuthorizationRefused extends RuntimeException
{
    private function __construct(public readonly string $ability)
    {
        parent::__construct('Permission denied: '.$ability);
    }

    public static function of(string $ability): self
    {
        return new self($ability);
    }
}
