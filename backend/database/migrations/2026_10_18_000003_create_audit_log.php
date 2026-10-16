<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Append-only audit log (not a business table: no standardColumns). Triggers refuse UPDATE, DELETE and TRUNCATE. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('event', 64);
            $t->string('entity_type', 64);
            $t->uuid('entity_id')->nullable();
            $t->jsonb('old_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->ipAddress()->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->string('request_id', 64)->nullable();
            $t->timestampTz('occurred_at');
        });
        DB::statement('CREATE INDEX audit_log_entity_index ON audit_log (entity_type, entity_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_user_index ON audit_log (user_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_occurred_at_index ON audit_log (occurred_at DESC)');

        // OR REPLACE: migrate:fresh drops tables, not functions.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_is_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is refused', TG_OP;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only()');
        DB::statement('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION audit_log_is_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_is_append_only()');
    }
};
