<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// A site manager asks HR to change one employee's daily transport fare at one of their sites (owner, 2026-11-20). The fare in force
// stays until HR approves: approving writes an employee_fare_overrides row from the date HR picks (today by default). The request keeps
// the amount asked for and the amount HR approved apart, and HR's reason when refused.
return new class extends Migration
{
    private const GRANTS = ['fare.request.own' => ['manager']];

    public function up(): void
    {
        Schema::create('fare_change_requests', function (Blueprint $t): void {
            $t->standardColumns();   // created_by: the site manager who asked
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignUuid('site_id')->constrained('sites')->restrictOnDelete();
            $t->money('current_amount', true);   // the fare in force when asked; null: none set
            $t->money('requested_amount');
            $t->text('reason');
            $t->string('status', 10)->default('pending');
            $t->money('approved_amount', true);
            $t->date('effective_from')->nullable();
            $t->text('refusal_reason')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->uuid('client_request_id')->nullable();
            $t->integer('version')->default(1);
        });
        DB::statement("alter table fare_change_requests add constraint fare_change_requests_status_check check (status in ('pending', 'approved', 'refused'))");
        DB::statement('alter table fare_change_requests add constraint fare_change_requests_amounts_check check (requested_amount >= 0 and (approved_amount is null or approved_amount >= 0))');
        DB::statement("create unique index fare_change_requests_one_pending on fare_change_requests (employee_id, site_id) where status = 'pending' and deleted_at is null");
        DB::statement('create unique index fare_change_requests_client_request on fare_change_requests (client_request_id) where client_request_id is not null');
        DB::statement('create index fare_change_requests_created_by on fare_change_requests (created_by)');

        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], array_filter($slugs, fn (string $s) => isset($roles[$s]))));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        Schema::dropIfExists('fare_change_requests');
    }
};
