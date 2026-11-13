<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = ['resource' => 'hr_forms', 'action' => 'approve_leave_request', 'scope' => 'all'];

    public function up(): void
    {
        $id = (string) Str::uuid7();
        DB::table('permissions')->insert(['id' => $id, ...self::PERMISSION, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->whereIn('slug', ['hr', 'ceo'])->pluck('id') as $role) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where(self::PERMISSION)->delete();
    }
};
