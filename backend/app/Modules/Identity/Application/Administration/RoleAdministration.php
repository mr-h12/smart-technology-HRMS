<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Administration;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Developer-console roles and their permission grants. */
final class RoleAdministration
{
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $counts = User::query()->selectRaw('role_id, count(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        return array_values(Role::with('permissions')->orderBy('id')->get()
            ->map(fn (Role $r) => $this->row($r, (int) ($counts[$r->id] ?? 0)))->all());
    }

    /**
     * @param  array{slug: string, name: string, name_ar: string}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $role = Role::create($data);
            AuditLog::record(IdentityAuditEvents::ROLE_CREATED, 'roles', (string) $role->id, null, $data);

            return $this->row($role);
        });
    }

    /**
     * @param  array{name?: string, name_ar?: string}  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $role = Role::findOrFail($id);
            $role->fill($data);
            $changed = $role->getDirty();
            if ($changed !== []) {
                $old = array_intersect_key($role->getOriginal(), $changed);
                $role->save();
                AuditLog::record(IdentityAuditEvents::ROLE_UPDATED, 'roles', (string) $role->id, $old, $changed);
            }

            return $this->row($role);
        });
    }

    /**
     * @param  list<string>  $permissionIds
     * @return array<string, mixed>
     */
    public function syncPermissions(string $id, array $permissionIds): array
    {
        return DB::transaction(function () use ($id, $permissionIds): array {
            $role = Role::findOrFail($id);
            $old = self::triples($role);
            $role->permissions()->sync($permissionIds);
            $new = self::triples($role->load('permissions'));
            if ($old !== $new) {
                AuditLog::record(IdentityAuditEvents::ROLE_PERMISSIONS_UPDATED, 'roles', (string) $role->id, ['permissions' => $old], ['permissions' => $new]);
            }
            self::assertRoleManagerRemains('permission_ids');

            return $this->row($role);
        });
    }

    /** @return list<array{id: string, resource: string, action: string, scope: string, key: string}> */
    public function permissions(): array
    {
        return array_values(Permission::query()->orderBy('resource')->orderBy('action')->orderBy('scope')->get()
            ->map(fn (Permission $p) => [
                'id' => (string) $p->id,
                'resource' => $p->resource,
                'action' => $p->action,
                'scope' => $p->scope,
                'key' => "{$p->resource}.{$p->action}.{$p->scope}",
            ])->all());
    }

    /** Keeps the developer console reachable; callers run it inside their transaction, after the change. */
    public static function assertRoleManagerRemains(string $field): void
    {
        // ponytail: one transaction-scoped lock serialises concurrent strips, which READ COMMITTED alone would let both pass.
        DB::select('select pg_advisory_xact_lock(?)', [crc32('identity.role_manager')]);
        $remains = User::where('is_active', true)
            ->whereHas('role.permissions', fn (Builder $q) => $q->where(['resource' => 'role', 'action' => 'manage', 'scope' => 'all']))
            ->exists();
        if (! $remains) {
            throw ValidationException::withMessages([$field => __('identity.last_role_manager')]);
        }
    }

    /** @return list<string> sorted `resource.action.scope` triples */
    private static function triples(Role $role): array
    {
        $triples = $role->permissions->map(fn (Permission $p) => "{$p->resource}.{$p->action}.{$p->scope}")->all();
        sort($triples);

        return $triples;
    }

    /** @return array<string, mixed> */
    private function row(Role $role, ?int $usersCount = null): array
    {
        return [
            'id' => (string) $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            'name_ar' => $role->name_ar,
            'permissions' => self::triples($role),
            'users_count' => $usersCount ?? User::where('role_id', $role->id)->count(),
        ];
    }
}
