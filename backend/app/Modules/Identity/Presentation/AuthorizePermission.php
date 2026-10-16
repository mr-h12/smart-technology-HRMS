<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `permission:resource.action` on a route; leaves the decision in the request for row-level scoping. */
final class AuthorizePermission
{
    public const ATTRIBUTE = 'identity.authorization';

    public function __construct(private readonly AuthorizeAction $authorize) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            throw new AuthenticationException;
        }

        $decision = $this->authorize->decide((string) $userId, $ability);
        if (! $decision->granted()) {
            throw AuthorizationRefused::of($ability);
        }
        $request->attributes->set(self::ATTRIBUTE, $decision);

        return $next($request);
    }
}
