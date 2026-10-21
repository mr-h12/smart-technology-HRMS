<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The api guard: `Authorization: Bearer …` to a user, or null (401) when expired, revoked, inactive or locked. */
final class BearerSessionResolver
{
    public function forRequest(Request $request): ?User
    {
        $presented = $request->bearerToken();
        if ($presented === null || $presented === '') {
            return null;
        }

        try {
            $token = SessionToken::fromPresented($presented);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Soft-deleted (signed-out or expired) rows are excluded by the model scope.
        $session = UserSession::where('session_id', $token->fingerprint())->first();
        if ($session === null) {
            return null;
        }

        $now = now();
        if (IdleTimeout::hasExpired($session->last_activity_at, $now)) {
            $session->delete();

            return null;
        }

        $user = $session->user;
        if ($user === null || ! $user->is_active || LockoutPolicy::isLocked($user->locked_until, $now)) {
            return null;
        }

        if (IdleTimeout::needsTouch($session->last_activity_at, $now)) {
            $session->update(['last_activity_at' => $now]);
        }
        $request->attributes->set(SessionAttribute::NAME, (string) $session->id);

        return $user;
    }
}
