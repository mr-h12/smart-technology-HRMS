<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    // View as: the developer uses the app as another account without its password (owner, 2026-11-05).
    private const GRANTS = ['user.impersonate.all' => ['developer']];

    public function up(): void
    {
        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], $slugs));
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
    }
};
