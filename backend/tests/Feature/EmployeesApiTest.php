<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('hr');
    }

    /** @param array<string, mixed> $over */
    private function payload(array $over = []): array
    {
        return [
            'name_ar' => 'أحمد علي',
            'gender' => 'male',
            'type' => 'office',
            'effective_from' => '2026-01-01',
            'salary' => '9000',
            ...$over,
        ];
    }

    private function make(array $over = []): string
    {
        return $this->postJson('/api/v1/employees', $this->payload($over))->assertCreated()->json('data.id');
    }

    public function test_codes_are_allocated_sequentially(): void
    {
        $this->postJson('/api/v1/employees', $this->payload())->assertCreated()->assertJsonPath('data.code', 1);
        $this->postJson('/api/v1/employees', $this->payload(['name_ar' => 'منى']))->assertCreated()->assertJsonPath('data.code', 2);
    }

    public function test_validation_rules(): void
    {
        $this->postJson('/api/v1/employees', $this->payload(['national_id' => '123']))->assertStatus(422);
        $this->postJson('/api/v1/employees', $this->payload(['pay_method' => 'bank']))->assertStatus(422);
        $this->postJson('/api/v1/employees', $this->payload(['pay_method' => 'bank', 'bank_account' => '1234']))->assertCreated();
        $this->postJson('/api/v1/employees', $this->payload(['salary' => '9999999999999']))->assertStatus(422);
        $this->postJson('/api/v1/employees', $this->payload(['type' => 'day_laborer', 'salary' => null]))->assertStatus(422);
        $this->postJson('/api/v1/employees', $this->payload(['salary' => null]))->assertStatus(422);
        $this->postJson('/api/v1/employees', $this->payload(['type' => 'day_laborer', 'salary' => null, 'daily_wage' => '250']))->assertCreated();
    }

    public function test_list_filters_by_type_in_force_today_and_searches_arabic(): void
    {
        $id = $this->make();
        $this->make(['name_ar' => 'منى', 'national_id' => '29801011234567']);
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'engineer', 'effective_from' => '2026-02-01'])->assertCreated();
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'day_laborer', 'effective_from' => '2999-01-01'])->assertCreated();

        $this->getJson('/api/v1/employees?type=engineer')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/employees?type=office')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/employees?search='.urlencode('أحمد'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/employees?search=29801011234567')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/employees?per_page=1')->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.total_pages', 2);
    }

    public function test_search_matches_the_exact_code(): void
    {
        $this->make();
        $this->make();
        $this->getJson('/api/v1/employees?search=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 2);
        $this->getJson('/api/v1/employees?search=02')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/employees?search=99999999999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_show_ignores_future_pay_change_and_returns_decimal_strings(): void
    {
        $id = $this->make();
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2999-01-01', 'salary' => '12000.5'])->assertCreated();

        $this->getJson("/api/v1/employees/$id")->assertOk()
            ->assertJsonPath('data.current_type', 'office')
            ->assertJsonPath('data.current_pay.salary', '9000.000000')
            ->assertJsonPath('data.current_pay.raise', '0.000000')
            ->assertJsonCount(2, 'data.pay_changes')
            ->assertJsonCount(1, 'data.type_changes');
    }

    public function test_money_round_trips_without_float_drift(): void
    {
        $id = $this->make(['salary' => '0.1']);
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-03-01', 'salary' => '8999.999999'])->assertCreated();
        $this->assertSame('0.100000', $this->getJson("/api/v1/employees/$id")->json('data.pay_changes.0.salary'));
        $this->assertSame('8999.999999', $this->getJson("/api/v1/employees/$id")->json('data.current_pay.salary'));
    }

    public function test_duplicate_effective_from_is_422(): void
    {
        $id = $this->make();
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'engineer', 'effective_from' => '2026-01-01'])->assertStatus(422);
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-01-01', 'salary' => '1'])->assertStatus(422);
    }

    public function test_pay_change_is_validated_against_type_in_force(): void
    {
        $id = $this->make();
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-05-01', 'daily_wage' => '200'])->assertStatus(422);
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'day_laborer', 'effective_from' => '2026-04-01'])->assertCreated();
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-05-01', 'daily_wage' => '200'])->assertCreated();
    }

    public function test_patch_identity_and_soft_delete(): void
    {
        $id = $this->make();
        $this->patchJson("/api/v1/employees/$id", ['phone' => '0100', 'department' => 'IT'])->assertOk()->assertJsonPath('data.phone', '0100');
        $this->patchJson("/api/v1/employees/$id", ['pay_method' => 'bank'])->assertStatus(422);
        $this->deleteJson("/api/v1/employees/$id")->assertNoContent();
        $this->getJson("/api/v1/employees/$id")->assertNotFound();
        $this->getJson('/api/v1/employees')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/employees/not-a-uuid')->assertNotFound();
    }

    public function test_document_upsert(): void
    {
        $id = $this->make();
        $this->putJson("/api/v1/employees/$id/documents/work_stub", ['received_at' => '2026-02-01'])->assertOk();
        $this->putJson("/api/v1/employees/$id/documents/work_stub", ['received_at' => '2026-02-02', 'note' => 'x'])->assertOk();
        $this->putJson("/api/v1/employees/$id/documents/bogus", [])->assertNotFound();
        $docs = $this->getJson("/api/v1/employees/$id")->json('data.documents');
        $this->assertCount(1, $docs);
        $this->assertSame('2026-02-02', $docs[0]['received_at']);
    }

    public function test_null_or_empty_raise_defaults_to_zero(): void
    {
        $id = $this->make(['raise' => null]);
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-03-01', 'salary' => '5', 'raise' => ''])->assertCreated();
        $this->postJson("/api/v1/employees/$id/pay-changes", ['effective_from' => '2026-04-01', 'salary' => '5', 'raise' => null])->assertCreated();
        $this->assertSame('0.000000', $this->getJson("/api/v1/employees/$id")->json('data.pay_changes.0.raise'));
    }

    public function test_money_with_trailing_newline_is_rejected(): void
    {
        // TrimStrings would hide the newline before the rule sees it.
        $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/v1/employees', $this->payload(['salary' => "9000\n"]))->assertStatus(422);
    }

    public function test_soft_deleted_type_change_is_ignored_by_type_filter(): void
    {
        $id = $this->make();
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'engineer', 'effective_from' => '2026-02-01'])->assertCreated();
        EmployeeTypeChange::where('employee_id', $id)->where('type', 'engineer')->delete();

        $this->getJson('/api/v1/employees?type=engineer')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/employees?type=office')->assertJsonCount(1, 'data');
    }

    public function test_list_rows_carry_current_type_ignoring_future_and_deleted_changes(): void
    {
        $id = $this->make();
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'engineer', 'effective_from' => '2026-02-01'])->assertCreated();
        $this->postJson("/api/v1/employees/$id/type-changes", ['type' => 'day_laborer', 'effective_from' => '2999-01-01'])->assertCreated();
        $this->getJson('/api/v1/employees')->assertJsonPath('data.0.current_type', 'engineer');

        EmployeeTypeChange::where('employee_id', $id)->where('type', 'engineer')->delete();
        $this->getJson('/api/v1/employees')->assertJsonPath('data.0.current_type', 'office');
    }

    public function test_summary_counts_live_employees_by_the_type_in_force_today_in_one_query(): void
    {
        $this->make();
        $moved = $this->make();
        $this->make(['type' => 'day_laborer', 'salary' => null, 'daily_wage' => '350']);
        $gone = $this->make();
        $this->postJson("/api/v1/employees/$moved/type-changes", ['type' => 'engineer', 'effective_from' => '2026-02-01'])->assertCreated();
        $this->postJson("/api/v1/employees/$moved/type-changes", ['type' => 'permanent_worker', 'effective_from' => '2999-01-01'])->assertCreated();
        $this->deleteJson("/api/v1/employees/$gone")->assertNoContent();

        DB::enableQueryLog();
        $this->getJson('/api/v1/employees/summary')->assertOk()->assertExactJsonStructure(['data', 'meta' => ['request_id']])
            ->assertJsonPath('data', ['total' => 3, 'office' => 1, 'engineer' => 1, 'permanent_worker' => 0, 'day_laborer' => 1]);
        $this->assertCount(1, preg_grep('/from "employees"/', array_column(DB::getQueryLog(), 'query')));
    }

    public function test_summary_needs_employee_view(): void
    {
        Role::create(['slug' => 'empty', 'name' => 'Empty', 'name_ar' => 'فارغ']);
        $this->signIn('empty');
        $this->getJson('/api/v1/employees/summary')->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
    }
}
