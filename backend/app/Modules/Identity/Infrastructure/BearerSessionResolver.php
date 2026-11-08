<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Authentication\ViewAs;
use App\Modules\Identity\Domain\Authentication\ViewAsEnding;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * The api guard: `Authorization: Bearer …` to a user, or null (401) when expired, revoked, inactive or locked.
 * On a view-as session the user is the target, marked with the developer; the view-as ends here once expired or revoked.
 */
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

        $viewAs = $session->impersonator_id === null ? null : $this->viewAs($session, $now);
        if ($session->impersonator_id !== null && $viewAs === null) {
            return null;
        }

        $user = $session->user;
        if ($user === null || ! $user->canSignIn($now)) {
            if ($viewAs !== null) {
                $session->endViewAs(ViewAsEnding::Revoked);
            }

            return null;
        }
        $user->viewAs = $viewAs;

        if (IdleTimeout::needsTouch($session->last_activity_at, $now)) {
            $session->update(['last_activity_at' => $now]);
        }
        $request->attributes->set(SessionAttribute::NAME, (string) $session->id);

        return $user;
    }

    /** The live view-as, or null once it has ended (it ends here: expired, or the developer can no longer start one). */
    private function viewAs(UserSession $session, Carbon $now): ?ViewAs
    {
        $by = User::find($session->impersonator_id);
        $viewAs = new ViewAs((string) $session->impersonator_id, (string) $by?->name,
            ($session->expires_at ?? throw new LogicException('A view-as session always has expires_at.'))->toDateTimeImmutable());
        $ending = match (true) {
            $viewAs->hasExpired($now) => ViewAsEnding::Expired,
            $by === null || ! $by->canSignIn($now) || ! RolePermissions::holds((string) $by->role_id, ViewAs::ABILITY) => ViewAsEnding::Revoked,
            default => null,
        };
        if ($ending !== null) {
            $session->endViewAs($ending);

            return null;
        }

        return $viewAs;
    }
}
