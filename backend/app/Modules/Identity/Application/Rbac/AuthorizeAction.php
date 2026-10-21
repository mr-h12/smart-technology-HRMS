<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Rbac;

use App\Modules\Identity\Domain\Rbac\PermissionDecision;
use App\Modules\Identity\Domain\Rbac\Scope;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;

/** Answers from the user's role grants alone: no role slug passes on its own. */
final class AuthorizeAction
{
    /** @param string $ability `resource.action` */
    public function decide(string $userId, string $ability): PermissionDecision
    {
        [$resource, $action] = explode('.', $ability, 2);
        $scopes = $this->roleOf($userId)?->permissions()->where(['resource' => $resource, 'action' => $action])->pluck('scope')->all() ?? [];

        return new PermissionDecision(array_values(array_map(fn (string $s) => Scope::from($s), $scopes)));
    }

    /** @return list<string> sorted `resource.action.scope` triples */
    public function permissionsOf(string $userId): array
    {
        $triples = $this->roleOf($userId)?->permissions->map(fn (Permission $p) => "{$p->resource}.{$p->action}.{$p->scope}")->all() ?? [];
        sort($triples);

        return $triples;
    }

    // A soft-deleted role resolves to null and so grants nothing.
    private function roleOf(string $userId): ?Role
    {
        return User::find($userId)?->role;
    }
}
