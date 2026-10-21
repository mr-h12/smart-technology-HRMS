<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Authentication\AuthenticateUser;
use App\Modules\Identity\Application\Authentication\Profile;
use App\Modules\Identity\Application\Authentication\SignOut;
use App\Modules\Identity\Domain\Authentication\IdleTimeout;
use App\Modules\Identity\Domain\Authentication\SessionAttribute;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        return ApiEnvelope::single($request, [
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'idle_timeout_seconds' => IdleTimeout::HOURS * 3600,
            'user' => $this->profile->for($session['user_id']),
        ], 201);
    }

    public function logout(Request $request, SignOut $signOut): Response
    {
        $sessionId = $request->attributes->get(SessionAttribute::NAME);
        $signOut->handle((string) $request->user()?->getAuthIdentifier(), is_string($sessionId) ? $sessionId : null);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->profile->for((string) $request->user()?->getAuthIdentifier()));
    }
}
