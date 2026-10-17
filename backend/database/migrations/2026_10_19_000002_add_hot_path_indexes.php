<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Postgres does not index a foreign key's referencing column on its own; the trigram indexes serve the list's ILIKE '%…%' search.
return new class extends Migration
{
    private const FOREIGN_KEYS = [
        'user_sessions' => 'user_id',
        'users' => 'role_id',
        'fares' => 'site_id',
        'manager_sites' => 'site_id',
        'employee_manager_assignments' => 'manager_id',
        'role_permissions' => 'permission_id',
    ];

    private const SEARCHED = ['name_ar', 'name_en', 'national_id'];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->index($column));
        }

        DB::statement('create extension if not exists pg_trgm');
        foreach (self::SEARCHED as $column) {
            DB::statement("create index employees_{$column}_trgm_index on employees using gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach (self::SEARCHED as $column) {
            DB::statement("drop index employees_{$column}_trgm_index");
        }
        DB::statement('drop extension if exists pg_trgm');

        foreach (self::FOREIGN_KEYS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex([$column]));
        }
    }
};
