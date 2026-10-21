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
        Schema::create('roles', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('name', 128);
            $t->string('name_ar', 128);
            $t->string('slug', 64);
        });

        Schema::create('users', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->smallInteger('failed_login_attempts')->default(0);
            $t->timestampTz('locked_until')->nullable();
        });

        Schema::create('user_sessions', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('session_id', 64);
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestampTz('last_activity_at')->index();
        });

        // Unique among live rows only, so a soft-deleted value can be reused.
        DB::statement('create unique index roles_slug_unique on roles (slug) where deleted_at is null');
        DB::statement('create unique index roles_name_unique on roles (name) where deleted_at is null');
        DB::statement('create unique index roles_name_ar_unique on roles (name_ar) where deleted_at is null');
        DB::statement('create unique index users_email_unique on users (email) where deleted_at is null');
        DB::statement('create unique index user_sessions_session_id_unique on user_sessions (session_id) where deleted_at is null');
        DB::statement('alter table users add constraint users_failed_login_attempts_check check (failed_login_attempts >= 0)');

        $now = now();
        DB::table('roles')->insert(array_map(fn (array $r) => [
            'id' => (string) Str::uuid7(), 'slug' => $r[0], 'name' => $r[1], 'name_ar' => $r[2],
            'created_at' => $now, 'updated_at' => $now,
        ], [
            ['developer', 'Developer', 'مطور'],
            ['ceo', 'CEO', 'الرئيس التنفيذي'],
            ['hr', 'HR', 'الموارد البشرية'],
            ['manager', 'Manager', 'مدير'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};
