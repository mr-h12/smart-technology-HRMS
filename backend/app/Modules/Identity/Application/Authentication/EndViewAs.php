<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\RefusalReason;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Authentication\ViewAsEnding;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Illuminate\Support\Facades\DB;

/**
 * "Return to my account": the view-as session is swapped for a fresh one on the developer's account.
 * The guard has already ended it (401) if it expired or the developer may no longer sign in or view as.
 */
final class EndViewAs
{
    /**
     * @return array{token: string, user: User} the developer
     *
     * @throws AuthenticationRefused not_viewing_as (409) on a normal session
     */
    public function handle(?string $sessionId, ?string $ip, ?string $userAgent): array
    {
        $outcome = DB::transaction(function () use ($sessionId, $ip, $userAgent): ?array {
            // The row lock makes a concurrent return or guard expiry find it ended: one token, one VIEW_AS_ENDED.
            $session = UserSession::whereKey($sessionId)->whereNotNull('impersonator_id')->lockForUpdate()->first();
            if ($session === null) {
                return null;
            }
            $developer = User::findOrFail($session->impersonator_id);
            $token = SessionToken::issue();
            $fresh = $developer->sessions()->create([
                'session_id' => $token->fingerprint(),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'last_activity_at' => now(),
            ]);
            $session->endViewAs(ViewAsEnding::Returned, (string) $fresh->id);

            return ['token' => $token->value, 'user' => $developer];
        });

        return $outcome ?? throw AuthenticationRefused::because(RefusalReason::NotViewingAs);
    }
}
