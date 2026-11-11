<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR's per-period payroll inputs and overrides (design 2026-11-09, "Data (new tables)"). Blank = 0; an override's revert is a soft
// delete with a reason. Both are audited and versioned like attendance days.
return new class extends Migration
{
    private const AMOUNTS = ['bonus', 'deduction', 'negligence', 'advances', 'insurance_employee', 'insurance_company', 'income_tax'];

    private const COUNTS = ['lateness_exemption_minutes', 'travel_days', 'tower_days', 'ot_minutes'];

    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('employee_type', 20);
            $t->date('period_start');
            foreach (self::AMOUNTS as $c) {
                $t->money($c, true);
            }
            $t->text('deduction_reason')->nullable();
            foreach (self::COUNTS as $c) {
                $t->integer($c)->nullable();
            }
            $t->text('note')->nullable();
            $t->integer('version')->default(1);
        });
        DB::statement('create unique index payroll_entries_live_unique on payroll_entries (employee_id, employee_type, period_start) where deleted_at is null');
        foreach ([...self::AMOUNTS, ...self::COUNTS] as $c) {
            DB::statement("alter table payroll_entries add constraint payroll_entries_{$c}_check check ({$c} is null or {$c} >= 0)");
        }

        Schema::create('payroll_overrides', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('employee_type', 20);
            $t->date('period_start');
            $t->string('component', 32);
            $t->money('amount');
            $t->text('reason');
            $t->money('computed_at_override');
            $t->text('revert_reason')->nullable();
            $t->integer('version')->default(1);
        });
        DB::statement('create unique index payroll_overrides_live_unique on payroll_overrides (employee_id, employee_type, period_start, component) where deleted_at is null');
        DB::statement("alter table payroll_overrides add constraint payroll_overrides_reason_check check (btrim(reason) <> '')");
        DB::statement("alter table payroll_overrides add constraint payroll_overrides_revert_reason_check check (deleted_at is null or btrim(revert_reason) <> '')");

        foreach (['payroll_entries', 'payroll_overrides'] as $table) {
            DB::statement("alter table {$table} add constraint {$table}_employee_type_check check (employee_type in ('office', 'engineer', 'permanent_worker', 'day_laborer'))");
            DB::statement("alter table {$table} add constraint {$table}_version_check check (version >= 1)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_overrides');
        Schema::dropIfExists('payroll_entries');
    }
};
