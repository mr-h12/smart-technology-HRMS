<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Illuminate\Support\Facades\DB;

/** Ends this device's session only; idempotent. */
final class SignOut
{
    public function handle(string $userId, ?string $sessionId): void
    {
        if ($sessionId === null) {
            return;
        }
        DB::transaction(function () use ($userId, $sessionId): void {
            if (UserSession::whereKey($sessionId)->where('user_id', $userId)->delete() > 0) {
                AuditLog::record(IdentityAuditEvents::LOGOUT, 'users', $userId, ['session_id' => $sessionId]);
            }
        });
    }
}
