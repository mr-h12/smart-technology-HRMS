<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Modules\Identity\Application\Authentication\EndViewAs;
use App\Modules\Identity\Application\Authentication\Profile;
use App\Modules\Identity\Application\Authentication\SignOut;
use App\Modules\Identity\Application\Authentication\StartViewAs;
use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AuthController
{
    public function __construct(private readonly Profile $profile) {}

    public function login(Request $request, AuthenticateUser $authenticate): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $session = $authenticate->handle($data['email'], $data['password'], $request->ip(), $request->userAgent());

        return $this->signedIn($request, $session['token'], $this->profile->forId($session['user_id']));
    }

    public function startViewAs(Request $request, StartViewAs $start): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['bail', 'required', 'uuid', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $session = $start->handle($request->user() ?? throw new AuthenticationException, $this->sessionId($request), $data['user_id'], $data['reason'] ?? null, $request->ip(), $request->userAgent());

        return $this->signedIn($request, $session['token'], $this->profile->for($session['user']));
    }

    public function endViewAs(Request $request, EndViewAs $end): JsonResponse
    {
        $session = $end->handle($this->sessionId($request), $request->ip(), $request->userAgent());

        return $this->signedIn($request, $session['token'], $this->profile->for($session['user']));
    }

    public function logout(Request $request, SignOut $signOut): Response
    {
        $signOut->handle((string) $request->user()?->getAuthIdentifier(), $this->sessionId($request));

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->profile->for($request->user() ?? throw new AuthenticationException));
    }

    /** @param array<string, mixed> $profile */
    private function signedIn(Request $request, string $token, array $profile): JsonResponse
    {
        return ApiEnvelope::single($request, [
            'token' => $token,
            'token_type' => 'Bearer',
            'idle_timeout_seconds' => IdleTimeout::HOURS * 3600,
            'user' => $profile,
        ], 201);
    }

    private function sessionId(Request $request): ?string
    {
        $id = $request->attributes->get(SessionAttribute::NAME);

        return is_string($id) ? $id : null;
    }
}
