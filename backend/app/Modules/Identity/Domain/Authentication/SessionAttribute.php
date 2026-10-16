<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/** The request attribute the guard leaves the current user_sessions.id in, for logout. */
final class SessionAttribute
{
    public const NAME = 'identity.session_id';
}
