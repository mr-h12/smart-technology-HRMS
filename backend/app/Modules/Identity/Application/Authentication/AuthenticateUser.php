<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Authentication;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Authentication\LockoutPolicy;
use App\Modules\Identity\Domain\Authentication\RefusalReason;
use App\Modules\Identity\Domain\Authentication\SessionToken;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/** Login: lock first, then the password, then is_active (so a stranger cannot probe who was deactivated). */
final class AuthenticateUser
{
    /**
     * @return array{token: string, user_id: string}
     *
     * @throws AuthenticationRefused
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $ip, ?string $userAgent): array
    {
        $email = mb_strtolower(trim($email));

        // The closure returns the refusal: throwing inside would roll back the failure count.
        $outcome = DB::transaction(function () use ($email, $password, $ip, $userAgent): array|RefusalReason {
            $user = User::where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                AuditLog::record(IdentityAuditEvents::LOGIN_FAILED, 'users', null, null, ['email' => $email]);

                return RefusalReason::InvalidCredentials;
            }

            $now = now();
            if (LockoutPolicy::isLocked($user->locked_until, $now)) {
                AuditLog::record(IdentityAuditEvents::LOGIN_BLOCKED, 'users', $user->id, null, ['reason' => RefusalReason::AccountLocked->value]);

                return RefusalReason::AccountLocked;
            }

            if (! Hash::check($password, $user->password)) {
                $before = $user->failed_login_attempts;
                $lockedBefore = $user->locked_until?->toIso8601String();
                $user->failed_login_attempts++;
                $locks = LockoutPolicy::shouldLock($user->failed_login_attempts);
                if ($locks) {
                    $user->locked_until = Carbon::instance(LockoutPolicy::lockedUntil($now, config()->integer('identity.lockout_minutes')));
                }
                $user->save();
                AuditLog::record(IdentityAuditEvents::LOGIN_FAILED, 'users', $user->id, ['failed_login_attempts' => $before], ['failed_login_attempts' => $user->failed_login_attempts]);
                if ($locks) {
                    AuditLog::record(IdentityAuditEvents::ACCOUNT_LOCKED, 'users', $user->id, ['locked_until' => $lockedBefore], ['locked_until' => $user->locked_until?->toIso8601String()]);
                }

                return $locks ? RefusalReason::AccountLocked : RefusalReason::InvalidCredentials;
            }

            if (! $user->is_active) {
                AuditLog::record(IdentityAuditEvents::LOGIN_BLOCKED, 'users', $user->id, null, ['reason' => RefusalReason::AccountSuspended->value]);

                return RefusalReason::AccountSuspended;
            }

            $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
            $token = SessionToken::issue();
            $session = $user->sessions()->create([
                'session_id' => $token->fingerprint(),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'last_activity_at' => $now,
            ]);
            AuditLog::record(IdentityAuditEvents::LOGIN_SUCCEEDED, 'users', $user->id, null, ['session_id' => $session->id], $user->id);

            return ['token' => $token->value, 'user_id' => (string) $user->id];
        });

        if ($outcome instanceof RefusalReason) {
            throw AuthenticationRefused::because($outcome);
        }

        return $outcome;
    }
}
