<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// A site manager proposes a new daily wage for a day laborer who already exists (Youssef, 2026-11-19, #79):
// - kind: new_laborer (the proposal that comes with a new laborer, every existing row) or wage_change;
// - reason: the site manager's optional reason, which HR reads;
// - effective_from: the date HR approved a wage change from; a new laborer's wage starts on the hire date, as before.
// - employee.propose_wage.own: the site manager's permission, apart from employee.propose_laborer.own.
return new class extends Migration
{
    private const GRANTS = ['employee.propose_wage.own' => ['manager']];

    public function up(): void
    {
        Schema::table('employee_wage_proposals', function (Blueprint $t): void {
            $t->string('kind', 20)->default('new_laborer');
            $t->text('reason')->nullable();
            $t->date('effective_from')->nullable();
        });
        DB::statement("alter table employee_wage_proposals add constraint employee_wage_proposals_kind_check check (kind in ('new_laborer', 'wage_change'))");

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
        // A wage change has no meaning once the kind is gone: refuse instead of turning it into a new-laborer proposal.
        if (DB::table('employee_wage_proposals')->where('kind', 'wage_change')->exists()) {
            throw new RuntimeException('Wage-change proposals exist. Decide or remove them before rolling this migration back.');
        }
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        Schema::table('employee_wage_proposals', function (Blueprint $t): void {
            $t->dropColumn(['kind', 'reason', 'effective_from']);
        });
    }
};
