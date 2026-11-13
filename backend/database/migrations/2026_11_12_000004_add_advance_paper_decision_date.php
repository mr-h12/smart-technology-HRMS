<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_requests', function (Blueprint $table): void {
            $table->date('paper_decision_date')->nullable();
        });
        DB::statement("ALTER TABLE attendance_requests ADD CONSTRAINT attendance_requests_paper_decision_date_check
            CHECK (paper_decision_date IS NULL OR (type = 'advance' AND status IN ('approved', 'refused') AND paper_decision_date >= date))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_requests DROP CONSTRAINT attendance_requests_paper_decision_date_check');
        Schema::table('attendance_requests', function (Blueprint $table): void {
            $table->dropColumn('paper_decision_date');
        });
    }
};
