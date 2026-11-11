<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
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

    public function test_phone_is_an_egyptian_mobile_number_kept_as_text(): void
    {
        foreach (['01012345678', '01112345678', '01212345678', '01512345678'] as $phone) {
            $this->postJson('/api/v1/employees', $this->payload(['phone' => $phone]))->assertCreated()->assertJsonPath('data.phone', $phone);
        }
        $this->postJson('/api/v1/employees', $this->payload(['phone' => null]))->assertCreated()->assertJsonPath('data.phone', null);
        foreach (['0100', '01312345678', '0101234567', '010123456789', '031234567', '+201012345678', '010 1234 5678', '٠١٠١٢٣٤٥٦٧٨'] as $phone) {
            $this->postJson('/api/v1/employees', $this->payload(['phone' => $phone]))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        // TrimStrings would hide the newline before the rule sees it.
        $this->withoutMiddleware(TrimStrings::class)->postJson('/api/v1/employees', $this->payload(['phone' => "01012345678\n"]))->assertStatus(422);
        $id = $this->make();
        $this->patchJson("/api/v1/employees/$id", ['phone' => '0100'])->assertStatus(422);
    }

    public function test_a_bad_phone_is_explained_in_the_request_language(): void
    {
        $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/employees', $this->payload(['phone' => '0100']))->assertStatus(422)
            ->assertJsonFragment(['يجب أن يكون رقم الهاتف رقم موبايل مصري من 11 رقمًا يبدأ بـ 010 أو 011 أو 012 أو 015.']);
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/employees', $this->payload(['phone' => '0100']))->assertStatus(422)
            ->assertJsonFragment(['The phone must be an Egyptian mobile number: 11 digits starting with 010, 011, 012 or 015.']);
    }

    public function test_the_ceo_receives_hr_notes_in_the_list_and_the_profile(): void
    {
        $id = $this->make(['hr_notes' => 'ملاحظة']);
        $this->signIn('ceo');
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('data.0.hr_notes', 'ملاحظة');
        $this->getJson("/api/v1/employees/$id")->assertOk()->assertJsonPath('data.hr_notes', 'ملاحظة');
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
        $this->patchJson("/api/v1/employees/$id", ['phone' => '01012345678', 'department' => 'IT'])->assertOk()->assertJsonPath('data.phone', '01012345678');
        $this->patchJson("/api/v1/employees/$id", ['pay_method' => 'bank'])->assertStatus(422);
        $this->deleteJson("/api/v1/employees/$id")->assertNoContent();
        $this->getJson("/api/v1/employees/$id")->assertNotFound();
        $this->getJson('/api/v1/employees')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/employees/not-a-uuid')->assertNotFound();
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

    /** @return list<string> the ids the list returns for this query string */
    private function listed(string $query = ''): array
    {
        return array_column($this->getJson('/api/v1/employees'.$query)->assertOk()->json('data'), 'id');
    }

    public function test_the_list_shows_active_employees_and_the_archive_those_whose_termination_date_has_passed(): void
    {
        $this->travelTo('2026-10-20 22:30:00');                                     // 2026-10-21 01:30 in Cairo (summer time, UTC+3)
        $left = $this->make(['termination_date' => '2026-10-20']);
        $lastDay = $this->make(['termination_date' => '2026-10-21']);               // the termination day is still worked
        $staying = $this->make();

        $this->assertSame([$lastDay, $staying], $this->listed());
        $this->assertSame([$lastDay, $staying], $this->listed('?archived=false'));
        $this->assertSame([$lastDay, $staying], $this->listed('?archived=0'));
        $this->assertSame([$left], $this->listed('?archived=true'));
        $this->assertSame([$left], $this->listed('?archived=1'));
        $this->getJson('/api/v1/employees?archived=yes')->assertStatus(422)->assertJsonPath('error.details.0.field', 'archived');
        $this->getJson('/api/v1/employees/summary')->assertJsonPath('data.total', 2)->assertJsonPath('data.office', 2);
    }

    public function test_a_manager_sees_no_archived_employee_not_even_their_own(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $manager = $this->makeUser('manager');
        $mine = $this->make(['type' => 'engineer']);
        $this->make(['type' => 'engineer', 'termination_date' => '2026-10-01']);
        $this->postJson("/api/v1/employees/$mine/manager-assignments", ['manager_id' => $manager->id, 'effective_from' => '2026-01-01'])->assertCreated();
        $this->patchJson("/api/v1/employees/$mine", ['termination_date' => '2026-10-01'])->assertOk();

        $this->actingAs($manager, 'api');
        $this->assertSame([], $this->listed('?archived=true'));
        $this->assertSame([], $this->listed());
        $this->getJson('/api/v1/employees/summary')->assertJsonPath('data.total', 0);
    }

    public function test_reactivating_clears_the_termination_and_brings_the_employee_back_to_the_list(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $id = $this->make(['termination_date' => '2026-10-01', 'termination_reason' => 'استقالة']);
        $this->assertSame([$id], $this->listed('?archived=true'));

        $this->patchJson("/api/v1/employees/$id", ['termination_date' => null, 'termination_reason' => null])->assertOk()
            ->assertJsonPath('data.termination_date', null)->assertJsonPath('data.termination_reason', null);
        $this->assertSame([$id], $this->listed());
        $this->assertSame([], $this->listed('?archived=true'));
        $audit = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->sole();
        $this->assertSame(['termination_date' => '2026-10-01', 'termination_reason' => 'استقالة'], json_decode($audit->old_values, true));
        $this->assertSame(['termination_date' => null, 'termination_reason' => null], json_decode($audit->new_values, true));
    }

    public function test_changing_the_code_needs_employee_edit_code(): void
    {
        $id = $this->make();
        $this->signIn('manager');
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 5])->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->signIn('ceo');
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 5])->assertStatus(403);
        $this->signIn('developer');
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 0])->assertOk()->assertJsonPath('data.code', 0);
        $this->signIn('hr');
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 1])->assertOk()->assertJsonPath('data.code', 1);
    }

    public function test_an_own_scope_edit_code_grant_is_refused_even_for_the_callers_own_employee(): void
    {
        $role = Role::create(['slug' => 'lead', 'name' => 'Lead', 'name_ar' => 'مشرف']);
        $role->permissions()->attach(Permission::create(['resource' => 'employee', 'action' => 'edit_code', 'scope' => 'own'])->getKey());
        $lead = $this->makeUser('lead');
        $mine = $this->make(['type' => 'engineer']);
        $this->make(['type' => 'engineer']);
        $this->postJson("/api/v1/employees/$mine/manager-assignments", ['manager_id' => $lead->id, 'effective_from' => '2026-01-01'])->assertCreated();

        $this->actingAs($lead, 'api');
        foreach ([['code' => 0], ['code' => 2], ['code' => 2, 'swap' => true]] as $body) {
            $this->patchJson("/api/v1/employees/$mine/code", $body)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        }
        $this->assertSame(1, DB::table('employees')->where('id', $mine)->value('code'));
    }

    public function test_a_free_code_is_saved_and_audited(): void
    {
        $id = $this->make();
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 0])->assertOk()->assertJsonPath('data.code', 0)->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.current_type', 'office');
        $audit = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->sole();
        $this->assertSame([['code' => 1], ['code' => 0]], [json_decode($audit->old_values, true), json_decode($audit->new_values, true)]);

        $this->patchJson("/api/v1/employees/$id/code", ['code' => 0])->assertOk();               // the same code changes nothing
        $this->assertSame(1, DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->count());
    }

    public function test_a_number_is_never_reissued_after_renumbering_the_top_employee_down(): void
    {
        $this->make();
        $top = $this->make();
        $this->patchJson("/api/v1/employees/$top/code", ['code' => 0])->assertOk();

        $this->postJson('/api/v1/employees', $this->payload())->assertCreated()->assertJsonPath('data.code', 3);
    }

    public function test_a_code_above_the_highest_issued_is_422_naming_the_highest(): void
    {
        $id = $this->make();
        $this->make();
        foreach (['ar', 'en'] as $locale) {
            $this->patchJson("/api/v1/employees/$id/code", ['code' => 3], ['Accept-Language' => $locale])->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'code')
                ->assertJsonPath('error.details.0.message', __('errors.code_above_highest', ['max' => 2], $locale));
        }
        $this->assertSame(1, DB::table('employees')->where('id', $id)->value('code'));
    }

    public function test_create_after_an_out_of_band_higher_code_continues_above_it(): void
    {
        $id = $this->make();
        DB::table('employees')->where('id', $id)->update(['code' => 50]);   // a row written outside EmployeeService::create

        $this->postJson('/api/v1/employees', $this->payload())->assertCreated()->assertJsonPath('data.code', 51);
        $this->postJson('/api/v1/employees', $this->payload())->assertCreated()->assertJsonPath('data.code', 52);
    }

    public function test_the_code_must_be_a_whole_number_from_0(): void
    {
        $id = $this->make();
        foreach ([[], ['code' => -1], ['code' => 'abc'], ['code' => 1.5], ['code' => 0, 'swap' => 'maybe']] as $body) {
            $this->patchJson("/api/v1/employees/$id/code", $body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 0])->assertOk()->assertJsonPath('data.code', 0);
        $this->getJson('/api/v1/employees?search=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/employees?search=00')->assertJsonCount(0, 'data');
    }

    public function test_a_deleted_employees_code_is_not_reused(): void
    {
        $id = $this->make();
        $gone = $this->make();
        $this->deleteJson("/api/v1/employees/$gone")->assertNoContent();

        foreach ([['code' => 2], ['code' => 2, 'swap' => true]] as $body) {
            $this->patchJson("/api/v1/employees/$id/code", $body, ['Accept-Language' => 'ar'])->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'code')
                ->assertJsonPath('error.details.0.message', __('errors.code_deleted_holder', [], 'ar'));
        }
        $this->assertSame(1, DB::table('employees')->where('id', $id)->value('code'));
    }

    public function test_a_code_held_by_a_live_employee_is_409_naming_them(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $id = $this->make();
        $this->make(['name_ar' => 'منى', 'name_en' => 'Mona', 'termination_date' => '2026-10-01']); // archived still holds the code
        $this->make(['name_ar' => 'سعيد']);                                                           // no English name

        $this->patchJson("/api/v1/employees/$id/code", ['code' => 2], ['Accept-Language' => 'ar'])->assertStatus(409)
            ->assertJsonPath('error', ['code' => 'code_taken', 'message' => 'الرقم الوظيفي 2 مستخدم للموظف منى.']);
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 2], ['Accept-Language' => 'en'])->assertStatus(409)
            ->assertJsonPath('error.message', 'ID 2 belongs to Mona.');
        $this->patchJson("/api/v1/employees/$id/code", ['code' => 3], ['Accept-Language' => 'en'])->assertStatus(409)
            ->assertJsonPath('error.message', 'ID 3 belongs to سعيد.');
        $this->assertSame(0, DB::table('audit_log')->where(['entity_type' => 'employees', 'event' => 'UPDATED'])->count());
    }

    public function test_swap_exchanges_the_two_codes_with_an_audit_entry_each(): void
    {
        $a = $this->make();
        $b = $this->make(['name_ar' => 'منى']);

        $this->patchJson("/api/v1/employees/$a/code", ['code' => 2, 'swap' => true])->assertOk()->assertJsonPath('data.code', 2);
        $this->assertSame(1, DB::table('employees')->where('id', $b)->value('code'));
        $audit = fn (string $id) => DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->get()
            ->map(fn ($r) => [json_decode($r->old_values, true), json_decode($r->new_values, true)])->all();
        $this->assertSame([[['code' => 1], ['code' => 2]]], $audit($a));
        $this->assertSame([[['code' => 2], ['code' => 1]]], $audit($b));

        $this->patchJson("/api/v1/employees/$a/code", ['code' => 0, 'swap' => 1])->assertOk()->assertJsonPath('data.code', 0);   // nobody holds 0: a plain change
    }

    public function test_zero_is_the_ceos_given_only_while_free_and_never_swapped(): void
    {
        $ceo = $this->make();
        $b = $this->make();
        $this->make();
        $this->patchJson("/api/v1/employees/$ceo/code", ['code' => 0])->assertOk()->assertJsonPath('data.code', 0);

        // nobody else takes 0, and the CEO swaps with no one: 422 on the field, not the 409 that offers a swap
        foreach ([[$b, ['code' => 0]], [$b, ['code' => 0, 'swap' => true]], [$ceo, ['code' => 2]], [$ceo, ['code' => 2, 'swap' => true]]] as [$id, $body]) {
            foreach (['ar', 'en'] as $locale) {
                $this->patchJson("/api/v1/employees/$id/code", $body, ['Accept-Language' => $locale])->assertStatus(422)
                    ->assertJsonPath('error.details.0.field', 'code')
                    ->assertJsonPath('error.details.0.message', __('errors.code_zero_ceo', [], $locale));
            }
        }
        $this->assertSame([0, 2], [DB::table('employees')->where('id', $ceo)->value('code'), DB::table('employees')->where('id', $b)->value('code')]);

        // the CEO may move to a free number; 0 is then free for whoever HR gives it to
        $this->patchJson("/api/v1/employees/$ceo/code", ['code' => 1])->assertOk()->assertJsonPath('data.code', 1);
        $this->patchJson("/api/v1/employees/$b/code", ['code' => 0])->assertOk()->assertJsonPath('data.code', 0);
    }

    public function test_swap_with_an_archived_holder(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $a = $this->make();
        $archived = $this->make(['termination_date' => '2026-10-01']);

        $this->patchJson("/api/v1/employees/$a/code", ['code' => 2, 'swap' => true])->assertOk()->assertJsonPath('data.code', 2);
        $this->assertSame(1, DB::table('employees')->where('id', $archived)->value('code'));
    }

    /** Runs $write once, straight after the first query that looks an employee up by code: another request acting between the lookup and the lock. */
    private function afterTheHolderLookup(callable $write): void
    {
        $done = false;
        DB::listen(function ($query) use (&$done, $write): void {
            if (! $done && str_contains($query->sql, '"code" = ?')) {
                $done = true;
                $write();
            }
        });
    }

    public function test_a_holder_that_moves_off_the_code_meanwhile_is_not_swapped(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->afterTheHolderLookup(fn () => DB::table('employees')->where('id', $b)->update(['code' => 0]));

        $this->patchJson("/api/v1/employees/$a/code", ['code' => 2, 'swap' => true])->assertOk()->assertJsonPath('data.code', 2);
        $this->assertSame(0, DB::table('employees')->where('id', $b)->value('code'));
    }

    public function test_a_holder_deleted_meanwhile_is_not_swapped(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->afterTheHolderLookup(fn () => DB::table('employees')->where('id', $b)->update(['deleted_at' => now()]));

        $this->patchJson("/api/v1/employees/$a/code", ['code' => 2, 'swap' => true])->assertStatus(422)->assertJsonPath('error.details.0.field', 'code');
        $this->assertSame([1, 2], [DB::table('employees')->where('id', $a)->value('code'), DB::table('employees')->where('id', $b)->value('code')]);
    }

    public function test_a_code_taken_between_the_check_and_the_save_is_422(): void
    {
        $id = $this->make();
        $other = $this->make();
        // Stands in for another write taking the code meanwhile: a raw write, after the check, inside the save's transaction.
        Employee::updating(fn () => DB::table('employees')->where('id', $other)->update(['code' => 0]));

        $this->patchJson("/api/v1/employees/$id/code", ['code' => 0], ['Accept-Language' => 'ar'])->assertStatus(422)
            ->assertJsonPath('error.details.0.message', __('errors.code_changed_meanwhile', [], 'ar'));
        $this->assertSame(1, DB::table('employees')->where('id', $id)->value('code'));
    }
}
