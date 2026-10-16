<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('resource', 64);
            $t->string('action', 64);
            $t->string('scope', 16);
            $t->string('description', 255)->nullable();
        });

        // A plain pivot: grant history lives in the audit log, not in soft-deleted rows.
        Schema::create('role_permissions', function (Blueprint $t): void {
            $t->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        DB::statement("alter table permissions add constraint permissions_scope_check check (scope in ('own', 'all'))");
        DB::statement('create unique index permissions_triple_unique on permissions (resource, action, scope) where deleted_at is null');

        $names = [
            'employee.view.own', 'employee.view.all', 'employee.create.all', 'employee.edit.all', 'employee.delete.all',
            'employee.assign_manager.all', 'site.view.own', 'site.view.all', 'site.manage.all', 'site.assign_manager.all',
            'user.view.all', 'user.manage.all', 'role.view.all', 'role.manage.all', 'audit_log.view.all',
        ];
        $now = now();
        $ids = [];
        $rows = [];
        foreach ($names as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            $ids[$name] = (string) Str::uuid7();
            $rows[] = ['id' => $ids[$name], 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('permissions')->insert($rows);

        $grants = [
            'developer' => array_values(array_filter($names, fn (string $n) => str_ends_with($n, '.all'))),
            'ceo' => ['employee.view.all', 'site.view.all', 'audit_log.view.all'],
            'hr' => [
                'employee.view.all', 'employee.create.all', 'employee.edit.all', 'employee.delete.all', 'employee.assign_manager.all',
                'site.view.all', 'site.manage.all', 'site.assign_manager.all', 'user.view.all',
            ],
            'manager' => ['employee.view.own', 'site.view.own'],
        ];
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach ($grants as $slug => $granted) {
            DB::table('role_permissions')->insert(array_map(
                fn (string $n) => ['role_id' => $roles[$slug], 'permission_id' => $ids[$n]],
                $granted,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
