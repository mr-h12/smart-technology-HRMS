<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer RequestGuard memoises its user for the life of the process, so a long-lived worker
 * (or a test) would let a revoked token ride the previous resolution. Forget it when a token is presented.
 */
final class ForgetResolvedGuards
{
    public function __construct(private readonly AuthManager $auth) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Only with a bearer token: forgetting unconditionally would drop a test's actingAs() user.
        if ($request->bearerToken() !== null) {
            $this->auth->forgetGuards();
        }

        return $next($request);
    }
}
