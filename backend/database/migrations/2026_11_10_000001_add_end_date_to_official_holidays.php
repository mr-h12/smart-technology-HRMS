<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An official holiday is a period: date is its first day, end_date its last (inclusive). Live holidays never overlap (owner, 2026-11-10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_holidays', function (Blueprint $t): void {
            $t->date('end_date')->nullable();
        });
        DB::statement('update official_holidays set end_date = date');   // every holiday so far is one day
        DB::statement('alter table official_holidays alter column end_date set not null');
        DB::statement('alter table official_holidays add constraint official_holidays_end_date_check check (end_date >= date)');
        DB::statement('drop index official_holidays_date_unique');
        DB::statement("alter table official_holidays add constraint official_holidays_no_overlap exclude using gist (daterange(date, end_date, '[]') with &&) where (deleted_at is null)");
    }

    public function down(): void
    {
        DB::statement('alter table official_holidays drop constraint official_holidays_no_overlap');
        DB::statement('create unique index official_holidays_date_unique on official_holidays (date) where deleted_at is null');
        Schema::table('official_holidays', fn (Blueprint $t) => $t->dropColumn('end_date'));
    }
};
