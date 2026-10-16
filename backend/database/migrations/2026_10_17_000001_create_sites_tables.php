<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('kind', 10);
            $t->string('leave_code', 20)->nullable();
            $t->boolean('outside_alexandria')->default(false);
            $t->boolean('active')->default(true);
        });

        Schema::create('site_allowance_rules', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('site_id')->constrained('sites');
            $t->date('effective_from');
            $t->string('mode', 20);
            $t->money('value');
            $t->unique(['site_id', 'effective_from']);
        });

        Schema::create('residences', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('name_ar');
        });

        // Names are unique among live rows only, so a soft-deleted name can be reused.
        DB::statement('create unique index sites_name_ar_unique on sites (name_ar) where deleted_at is null');
        DB::statement('create unique index residences_name_ar_unique on residences (name_ar) where deleted_at is null');

        Schema::create('fares', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('residence_id')->constrained('residences');
            $t->foreignUuid('site_id')->constrained('sites');
            $t->date('effective_from');
            $t->money('amount');
            $t->unique(['residence_id', 'site_id', 'effective_from']);
        });

        Schema::create('employee_fare_overrides', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->foreignUuid('site_id')->constrained('sites');
            $t->date('effective_from');
            $t->money('amount');
            $t->unique(['employee_id', 'site_id', 'effective_from']);
        });

        Schema::table('employees', function (Blueprint $t): void {
            $t->foreign('residence_id')->references('id')->on('residences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropForeign(['residence_id']));
        Schema::dropIfExists('employee_fare_overrides');
        Schema::dropIfExists('fares');
        Schema::dropIfExists('residences');
        Schema::dropIfExists('site_allowance_rules');
        Schema::dropIfExists('sites');
    }
};
