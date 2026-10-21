<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/** Why a login was refused; the value is the API error code and the lang key suffix. */
enum RefusalReason: string
{
    case InvalidCredentials = 'invalid_credentials';
    case AccountSuspended = 'account_suspended';
    case AccountLocked = 'account_locked';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials => 401,
            self::AccountSuspended => 403,
            self::AccountLocked => 423,
        };
    }
}
