<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = ['attendance.view.all', 'attendance.edit.all', 'attendance.lock.all', 'holiday.manage.all'];

    private const SETTINGS = ['worker_shift_start' => '08:00', 'worker_shift_end' => '16:00'];

    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('date');
            $t->foreignUuid('site_id')->nullable()->index()->constrained('sites')->restrictOnDelete();
            $t->time('in_time')->nullable();
            $t->time('out_time')->nullable();
            $t->time('scheduled_in')->nullable();
            $t->time('scheduled_out')->nullable();
            $t->smallInteger('late_minutes')->nullable();
            $t->smallInteger('ot_minutes')->nullable();
            $t->smallInteger('outside_minutes')->nullable();
            $t->money('outside_amount', true);
            $t->string('transport', 4)->nullable();
            $t->money('advance_amount', true);
            $t->text('note')->nullable();
            $t->string('source', 10);
            $t->integer('version')->default(1);
            // Plain, not partial: a cleared day is restored, never re-inserted.
            $t->unique(['employee_id', 'date']);
        });
        foreach (['late_minutes', 'ot_minutes', 'outside_minutes'] as $col) {
            DB::statement("alter table attendance_days add constraint attendance_days_{$col}_check check ({$col} is null or {$col} between 0 and 960)");
        }
        DB::statement("alter table attendance_days add constraint attendance_days_transport_check check (transport is null or transport in ('full', 'half', 'none'))");
        DB::statement("alter table attendance_days add constraint attendance_days_source_check check (source in ('entered', 'imported', 'derived'))");
        DB::statement('alter table attendance_days add constraint attendance_days_outside_amount_check check (outside_amount is null or outside_amount > 0)');
        DB::statement('alter table attendance_days add constraint attendance_days_advance_amount_check check (advance_amount is null or advance_amount > 0)');
        DB::statement('alter table attendance_days add constraint attendance_days_version_check check (version >= 1)');

        // A period is locked while its row exists with reopened_at null.
        Schema::create('attendance_locks', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('employee_type', 20);
            $t->date('period_start');
            $t->date('period_end');
            $t->uuid('locked_by')->nullable();
            $t->timestampTz('locked_at')->nullable();
            $t->uuid('reopened_by')->nullable();
            $t->timestampTz('reopened_at')->nullable();
            $t->text('reopen_reason')->nullable();
            $t->unique(['employee_type', 'period_start']);
        });
        DB::statement("alter table attendance_locks add constraint attendance_locks_employee_type_check check (employee_type in ('office', 'engineer', 'permanent_worker', 'day_laborer'))");

        Schema::create('official_holidays', function (Blueprint $t): void {
            $t->standardColumns();
            $t->date('date');
            $t->string('name', 128);
        });
        DB::statement('create unique index official_holidays_date_unique on official_holidays (date) where deleted_at is null');

        $taken = DB::table('sites')->where(['kind' => 'leave', 'active' => true])->whereNull('deleted_at')
            ->groupBy('leave_code')->havingRaw('count(*) > 1')->pluck('leave_code');
        if ($taken->isNotEmpty()) {
            throw new RuntimeException('Several active leave types share the code(s) '.$taken->implode(', ').'; deactivate all but one per code, then migrate again.');
        }
        DB::statement("create unique index sites_active_leave_code_unique on sites (leave_code) where kind = 'leave' and active and deleted_at is null");

        $now = now();
        DB::table('settings')->insert(array_map(
            fn (string $key, string $value) => ['id' => (string) Str::uuid7(), 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::SETTINGS),
            self::SETTINGS,
        ));

        $ids = [];
        foreach (self::PERMISSIONS as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            $ids[$name] = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $ids[$name], 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
        }

        $grants = ['developer' => self::PERMISSIONS, 'hr' => self::PERMISSIONS, 'ceo' => ['attendance.view.all']];
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach ($grants as $slug => $granted) {
            DB::table('role_permissions')->insert(array_map(
                fn (string $n) => ['role_id' => $roles[$slug], 'permission_id' => $ids[$n]],
                $granted,
            ));
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        foreach (self::PERMISSIONS as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
        DB::statement('drop index sites_active_leave_code_unique');
        Schema::dropIfExists('official_holidays');
        Schema::dropIfExists('attendance_locks');
        Schema::dropIfExists('attendance_days');
    }
};
