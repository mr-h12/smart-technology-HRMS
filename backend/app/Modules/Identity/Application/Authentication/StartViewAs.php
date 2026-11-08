<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Domain\Authentication\ViewAs;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use App\Modules\Identity\Infrastructure\RolePermissions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** The developer's session is swapped for one on the target's account; no password is involved. */
final class StartViewAs
{
    /**
     * @return array{token: string, user: User} the target, marked as viewed
     *
     * @throws ValidationException on user_id: self, not_allowed (the target may view as others too), inactive
     */
    public function handle(Authenticatable $developer, ?string $developerSessionId, string $targetId, ?string $reason, ?string $ip, ?string $userAgent): array
    {
        if (! $developer instanceof User) {
            throw new LogicException('The api guard yields only Identity users.');
        }
        $target = User::findOrFail($targetId);
        $refusal = match (true) {
            $target->is($developer) => 'self',
            RolePermissions::holds((string) $target->role_id, ViewAs::ABILITY) => 'not_allowed',
            // A locked target's session would be refused on the next request, signing the developer out.
            ! $target->canSignIn(now()) => 'inactive',
            default => null,
        };
        if ($refusal !== null) {
            throw ValidationException::withMessages(['user_id' => __("identity.view_as.{$refusal}")]);
        }

        return DB::transaction(function () use ($developer, $developerSessionId, $target, $reason, $ip, $userAgent): array {
            $now = now();
            // A concurrent start from the same session finds it gone and gets 401, so one session yields one view-as.
            if (UserSession::whereKey($developerSessionId)->where('user_id', $developer->id)->delete() === 0) {
                throw new AuthenticationException;
            }
            $viewAs = new ViewAs((string) $developer->id, $developer->name, ViewAs::expiresAt($now));
            $token = SessionToken::issue();
            $session = $target->sessions()->create([
                'session_id' => $token->fingerprint(),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'last_activity_at' => $now,
                'impersonator_id' => $developer->id,
                'expires_at' => $viewAs->expiresAt,
            ]);
            AuditLog::record(IdentityAuditEvents::VIEW_AS_STARTED, 'users', (string) $target->id, ['session_id' => $developerSessionId], [
                'session_id' => (string) $session->id,
                'expires_at' => Carbon::instance($viewAs->expiresAt)->toJSON(),
                'reason' => $reason,
            ], (string) $developer->id);
            $target->viewAs = $viewAs;

            return ['token' => $token->value, 'user' => $target];
        });
    }
}
