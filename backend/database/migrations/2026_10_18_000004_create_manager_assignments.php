<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unique index is the DB guarantee of one manager in force per employee on any date.
        Schema::create('employee_manager_assignments', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->foreignUuid('manager_id')->constrained('users');
            $t->date('effective_from');
            $t->unique(['employee_id', 'effective_from']);
        });

        Schema::create('manager_sites', function (Blueprint $t): void {
            $t->foreignUuid('manager_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $t->primary(['manager_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_sites');
        Schema::dropIfExists('employee_manager_assignments');
    }
};
