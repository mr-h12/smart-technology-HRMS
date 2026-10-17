<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Rbac;

use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\RolePermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/** Answers from the user's role grants alone: no role slug passes on its own. */
final class AuthorizeAction
{
    /** @param string $ability `resource.action` */
    public function decide(Authenticatable $user, string $ability): PermissionDecision
    {
        $prefix = "{$ability}.";
        $scopes = array_map(fn (string $t) => substr($t, strlen($prefix)), array_filter($this->permissionsOf($user), fn (string $t) => str_starts_with($t, $prefix)));

        return new PermissionDecision(array_values(array_map(fn (string $s) => Scope::from($s), $scopes)));
    }

    /** @return list<string> sorted `resource.action.scope` triples */
    public function permissionsOf(Authenticatable $user): array
    {
        // The guard's user, reused: no reload. Anything but our User grants nothing.
        return $user instanceof User ? RolePermissions::of((string) $user->role_id) : [];
    }
}
