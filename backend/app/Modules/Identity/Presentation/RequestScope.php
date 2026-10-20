<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Scope;
use Illuminate\Http\Request;
use LogicException;

/** Row-level reach of the route's permission: null for `all`, else the user whose own rows are visible. */
final class RequestScope
{
    public static function ownerId(Request $request): ?string
    {
        $decision = $request->attributes->get(AuthorizePermission::ATTRIBUTE);
        if (! $decision instanceof PermissionDecision) {
            throw new LogicException('RequestScope needs a permission: middleware on the route.');
        }

        return $decision->allows(Scope::All) ? null : (string) $request->user()?->getAuthIdentifier();
    }

    /** For a route that requires several permissions: null only when every one is held at `all`, whatever the middleware order. */
    public static function narrowestOwnerId(Request $request, string $ability, string ...$more): ?string
    {
        $all = true;
        foreach ([$ability, ...$more] as $name) {
            $decision = $request->attributes->get(AuthorizePermission::ATTRIBUTE.':'.$name);
            if (! $decision instanceof PermissionDecision) {
                throw new LogicException("RequestScope needs permission:{$name} on the route.");
            }
            $all = $all && $decision->allows(Scope::All);
        }

        return $all ? null : (string) $request->user()?->getAuthIdentifier();
    }
}
