<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Each role's grants, cached until something changes them. Every write to a role's grants must call forget() after it commits. */
final class RolePermissions
{
    /** @return list<string> sorted `resource.action.scope` triples; none for a missing or soft-deleted role */
    public static function of(string $roleId): array
    {
        // ponytail: a cache miss that reads just before a grant change commits can store the old list; add a version key if grants change often.
        return Cache::rememberForever(self::key($roleId), function () use ($roleId): array {
            $triples = Role::find($roleId)?->permissions->map(fn (Permission $p) => "{$p->resource}.{$p->action}.{$p->scope}")->all() ?? [];
            sort($triples);

            return $triples;
        });
    }

    public static function forget(string $roleId): void
    {
        Cache::forget(self::key($roleId));
    }

    /** After migrations: they write role_permissions directly. */
    public static function forgetAll(): void
    {
        if (Schema::hasTable('roles')) {
            DB::table('roles')->pluck('id')->each(fn (mixed $id) => self::forget((string) $id));
        }
    }

    private static function key(string $roleId): string
    {
        return "role-perms:{$roleId}";
    }
}
