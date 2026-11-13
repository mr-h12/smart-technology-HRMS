<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_requests', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignUuid('site_id')->constrained('sites')->restrictOnDelete();
            $t->date('date');
            $t->string('type', 10);
            $t->smallInteger('minutes')->nullable();
            $t->money('amount', true);
            $t->time('in_time')->nullable();
            $t->time('out_time')->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('reason')->nullable();
            $t->integer('version')->default(1);
            $t->index(['status', 'date']);
        });
        DB::statement("alter table attendance_requests add check (status in ('pending', 'approved', 'refused'))");
        DB::statement("alter table attendance_requests add check (
            (type = 'overtime' and minutes is not null and minutes between 1 and 960 and amount is null and in_time is not null and out_time is not null)
            or (type = 'advance' and amount is not null and amount > 0 and minutes is null and in_time is null and out_time is null)
        )");
        DB::statement('create unique index attendance_requests_one_per_day on attendance_requests (employee_id, date, type) where deleted_at is null');

        $id = (string) Str::uuid7();
        DB::table('permissions')->insert(['id' => $id, 'resource' => 'attendance_request', 'action' => 'decide', 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        $roles = DB::table('roles')->whereIn('slug', ['hr', 'developer'])->pluck('id');
        DB::table('role_permissions')->insert($roles->map(fn (string $role) => ['role_id' => $role, 'permission_id' => $id])->all());
    }

    public function down(): void
    {
        DB::table('permissions')->where(['resource' => 'attendance_request', 'action' => 'decide', 'scope' => 'all'])->delete();
        Schema::drop('attendance_requests');
    }
};
