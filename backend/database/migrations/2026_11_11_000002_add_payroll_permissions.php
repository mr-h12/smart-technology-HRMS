<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Payroll is HR's and the CEO's only, with no `.own` scope (design 2026-11-09, "API and screen"). `view.all` goes to every role holding
// employee.view.all (HR, CEO, developer; site managers hold only `.own`), `manage.all` to every role holding site.manage.all (HR,
// developer), so a role the developer renamed or added keeps working.
return new class extends Migration
{
    /** payroll action => the permission whose holders get it */
    private const FROM = ['view' => ['employee', 'view'], 'manage' => ['site', 'manage']];

    public function up(): void
    {
        $now = now();
        foreach (self::FROM as $action => [$resource, $fromAction]) {
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => 'payroll', 'action' => $action, 'scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
            $holders = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where(['p.resource' => $resource, 'p.action' => $fromAction, 'p.scope' => 'all'])->whereNull('p.deleted_at')
                ->pluck('rp.role_id');
            DB::table('role_permissions')->insert($holders->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id])->all());
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        DB::table('permissions')->where('resource', 'payroll')->delete();
    }
};
