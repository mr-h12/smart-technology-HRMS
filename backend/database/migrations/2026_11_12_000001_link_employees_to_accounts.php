<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A site manager can also be an employee (CLAUDE.md): user_id links the employee to their account, one live employee per account (owner, 2026-11-12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->foreignUuid('user_id')->nullable()->constrained('users');
        });
        DB::statement('create unique index employees_user_id_unique on employees (user_id) where deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index employees_user_id_unique');
        Schema::table('employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
    }
};
