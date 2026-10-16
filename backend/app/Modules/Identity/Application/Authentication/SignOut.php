<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Identity\Infrastructure\Eloquent\UserSession;

/** Ends this device's session only; idempotent. */
final class SignOut
{
    public function handle(string $userId, ?string $sessionId): void
    {
        if ($sessionId !== null) {
            UserSession::whereKey($sessionId)->where('user_id', $userId)->delete();
        }
    }
}
