<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t): void {
            $t->standardColumns();
            $t->unsignedInteger('code')->unique();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->string('fingerprint_name')->nullable()->unique();
            $t->string('gender', 10);
            $t->string('national_id', 14)->nullable()->unique();
            $t->string('insurance_no')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('department')->nullable();
            $t->uuid('residence_id')->nullable(); // FK added with the residences table
            $t->string('pay_method', 10)->default('cash');
            $t->string('bank_account')->nullable();
            $t->date('hire_date')->nullable();
            $t->date('contract_end_date')->nullable();
            $t->date('termination_date')->nullable();
            $t->text('termination_reason')->nullable();
            $t->text('hr_notes')->nullable();
            $t->uuid('crm_user_id')->nullable();
        });

        Schema::create('employee_type_changes', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->string('type', 20);
            $t->date('effective_from');
            $t->unique(['employee_id', 'effective_from']);
        });

        Schema::create('employee_pay_changes', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->date('effective_from');
            $t->money('salary', true);
            $t->money('raise')->default(0);
            $t->money('insured_basic', true);
            $t->money('daily_wage', true);
            $t->time('shift_start')->nullable();
            $t->time('shift_end')->nullable();
            $t->unique(['employee_id', 'effective_from']);
        });

        Schema::create('employee_documents', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees');
            $t->string('kind', 30);
            $t->date('received_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->text('note')->nullable();
            $t->unique(['employee_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_pay_changes');
        Schema::dropIfExists('employee_type_changes');
        Schema::dropIfExists('employees');
    }
};
