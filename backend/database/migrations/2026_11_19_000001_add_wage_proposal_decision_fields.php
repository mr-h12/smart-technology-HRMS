<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR's review of a proposed daily wage (owner, 2026-11-19):
// - refusal_reason: HR must say why it refused; older refusals have none.
// - approved_wage: the daily wage HR approved (the proposed one, or HR's revised amount), so the proposing site manager can see the
//   outcome of their own proposal. Earlier approvals take the first pay row their approval wrote.
// - client_request_id: the id the site manager's form sends with "add a new day laborer", so a double tap or a retry returns the
//   first answer instead of creating a second worker.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_wage_proposals', function (Blueprint $t): void {
            $t->text('refusal_reason')->nullable();
            $t->money('approved_wage', true);
            $t->uuid('client_request_id')->nullable();
        });
        DB::statement('alter table employee_wage_proposals add constraint employee_wage_proposals_approved_wage_check check (approved_wage is null or approved_wage > 0)');
        DB::statement('create unique index employee_wage_proposals_client_request on employee_wage_proposals (client_request_id) where client_request_id is not null');
        DB::statement("update employee_wage_proposals p set approved_wage = (
            select c.daily_wage from employee_pay_changes c
            where c.employee_id = p.employee_id and c.deleted_at is null and c.daily_wage is not null
            order by c.effective_from, c.created_at limit 1
        ) where p.status = 'approved' and p.approved_wage is null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists employee_wage_proposals_client_request');
        Schema::table('employee_wage_proposals', function (Blueprint $t): void {
            $t->dropColumn(['refusal_reason', 'approved_wage', 'client_request_id']);
        });
    }
};
