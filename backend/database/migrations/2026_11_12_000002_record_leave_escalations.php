<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignUuid('escalated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('escalated_at')->nullable();
        });

        $existing = DB::table('leave_requests')->count();
        $backfilled = DB::affectingStatement(<<<'SQL'
            WITH escalation AS (
                SELECT DISTINCT ON (entity_id) entity_id, COALESCE(on_behalf_of, user_id) AS actor, occurred_at
                FROM audit_log
                WHERE entity_type = 'leave_requests' AND event = 'UPDATED'
                    AND new_values->>'status' = 'escalated'
                ORDER BY entity_id, occurred_at DESC, id DESC
            )
            UPDATE leave_requests AS request
            SET escalated_at = escalation.occurred_at, escalated_by = users.id
            FROM escalation LEFT JOIN users ON users.id = escalation.actor
            WHERE request.id = escalation.entity_id
            SQL);
        Log::info('leave_escalation_backfill', [
            'backfilled' => $backfilled,
            'without_escalation_audit' => $existing - $backfilled,
        ]);
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn('escalated_at');
        });
    }
};
