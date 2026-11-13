<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_signoffs', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignUuid('site_id')->constrained('sites')->restrictOnDelete();
            $t->string('employee_type', 20);
            $t->date('period_start');
            $t->date('period_end');
            $t->foreignUuid('signed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('signed_at');
            $t->foreignUuid('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('returned_at')->nullable();
            $t->text('return_note')->nullable();
            $t->integer('version')->default(1);
            $t->unique(['employee_id', 'employee_type', 'period_start']);
        });
        DB::statement('create index attendance_requests_pending_employee_date on attendance_requests (employee_id, date) where deleted_at is null and status = \'pending\'');
    }

    public function down(): void
    {
        DB::statement('drop index attendance_requests_pending_employee_date');
        Schema::drop('attendance_signoffs');
    }
};
