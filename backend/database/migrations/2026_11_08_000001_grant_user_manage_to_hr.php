<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// HR creates accounts and sets passwords, for HR and team-lead accounts only (owner, 2026-11-08, R22).
// The "only" is UserAdministration's reach rule, not this grant. The grant cache is cleared after every migrate.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->hr(), 'permission_id' => $this->userManage()]);
    }

    public function down(): void
    {
        DB::table('role_permissions')->where(['role_id' => $this->hr(), 'permission_id' => $this->userManage()])->delete();
    }

    private function hr(): string
    {
        return (string) DB::table('roles')->where('slug', 'hr')->whereNull('deleted_at')->value('id');
    }

    private function userManage(): string
    {
        return (string) DB::table('permissions')->where(['resource' => 'user', 'action' => 'manage', 'scope' => 'all'])->whereNull('deleted_at')->value('id');
    }
};
