<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

use RuntimeException;

/** Rendered as 403 with its reason as the code (`permission_denied`, `account_out_of_reach`) in bootstrap/app.php. */
final class AuthorizationRefused extends RuntimeException
{
    private function __construct(public readonly string $ability, public readonly string $reason = 'permission_denied')
    {
        parent::__construct('Permission denied: '.$ability);
    }

    public static function of(string $ability): self
    {
        return new self($ability);
    }

    /** The account, or the role being given, holds an `.all` grant the actor lacks. */
    public static function outOfReach(): self
    {
        return new self('user.manage', 'account_out_of_reach');
    }
}
