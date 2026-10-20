<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Rbac;

use Illuminate\Support\Facades\DB;

final class PermissionHolders
{
    /** @return list<string> ids of the active, live users whose live role holds the live `resource.action.scope` triple */
    public function userIds(string $triple): array
    {
        [$resource, $action, $scope] = explode('.', $triple);

        return array_values(DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where(['p.resource' => $resource, 'p.action' => $action, 'p.scope' => $scope, 'u.is_active' => true])
            ->whereNull(['u.deleted_at', 'r.deleted_at', 'p.deleted_at'])
            ->orderBy('u.id')
            ->pluck('u.id')->map(fn (mixed $id) => (string) $id)->all());
    }
}
