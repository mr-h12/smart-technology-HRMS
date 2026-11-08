<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A view-as is the target's session with the developer in impersonator_id; audit rows written during it name the target in on_behalf_of. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $t): void {
            $t->foreignUuid('impersonator_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->timestampTz('expires_at')->nullable();
        });
        DB::statement('alter table user_sessions add constraint user_sessions_view_as_check check ((impersonator_id is null) = (expires_at is null))');
        // Adding a column is not an UPDATE: the append-only trigger does not fire.
        Schema::table('audit_log', function (Blueprint $t): void {
            $t->uuid('on_behalf_of')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', fn (Blueprint $t) => $t->dropColumn('on_behalf_of'));
        Schema::table('user_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('impersonator_id'));
        Schema::table('user_sessions', fn (Blueprint $t) => $t->dropColumn('expires_at'));
    }
};
