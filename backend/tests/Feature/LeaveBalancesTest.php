<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class LeaveBalancesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
    }

    private function balance(string $employeeId, ?int $year = null): TestResponse
    {
        return $this->getJson("/api/v1/employees/{$employeeId}/leave".($year === null ? '' : "?year={$year}"));
    }

    private function save(string $employeeId, string $date, string $code): void
    {
        app(AttendanceDays::class)->save($employeeId, $date, ['site_id' => $this->leave[$code]]);
    }

    public function test_an_annual_day_lowers_the_remaining_balance(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->balance($e)->assertOk()
            ->assertJsonPath('data.employee_id', $e)->assertJsonPath('data.year', 2026)->assertJsonPath('data.type', 'office')
            ->assertJsonPath('data.hire_date', '2020-01-01')->assertJsonPath('data.adjustment', null)
            ->assertJsonPath('data.balance.entitlement', 21)->assertJsonPath('data.balance.remaining', 21);

        $this->save($e, '2026-03-02', 'annual');
        $this->balance($e)->assertJsonPath('data.balance.used', 1)->assertJsonPath('data.balance.remaining', 20)
            ->assertJsonPath('data.balance.casual_used', 0)->assertJsonPath('data.balance.casual_remaining', 6);
    }

    public function test_a_casual_day_lowers_both_balances(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->save($e, '2026-03-02', 'casual');
        $this->balance($e)->assertJsonPath('data.balance.used', 1)->assertJsonPath('data.balance.remaining', 20)
            ->assertJsonPath('data.balance.casual_used', 1)->assertJsonPath('data.balance.casual_remaining', 5);
    }

    public function test_sick_and_unpaid_days_are_counted_apart(): void
    {
        $e = $this->employee('permanent_worker', '2020-01-01');
        $this->save($e, '2026-03-02', 'sick');
        $this->save($e, '2026-03-03', 'unpaid');
        $this->balance($e)->assertJsonPath('data.balance.sick_days', 1)->assertJsonPath('data.balance.unpaid_days', 1)
            ->assertJsonPath('data.balance.used', 0)->assertJsonPath('data.balance.remaining', 21);
    }

    public function test_used_counts_only_this_employees_stored_annual_and_casual_days(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $other = $this->employee('engineer', '2020-01-01');
        $days = app(AttendanceDays::class);
        $annual = $days->save($e, '2026-03-01', ['site_id' => $this->leave['annual']]);
        $this->save($e, '2026-03-02', 'casual');
        $days->save($e, '2026-03-03', ['site_id' => $this->workSite()]);
        $this->save($e, '2026-03-06', 'friday');
        $this->save($other, '2026-03-01', 'annual');
        $this->balance($e)->assertJsonPath('data.balance.used', 2);

        $days->clear($e, '2026-03-01', $annual['version']);
        $this->balance($e)->assertJsonPath('data.balance.used', 1);
    }

    public function test_only_the_requested_year_counts(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->save($e, '2025-12-31', 'annual');
        $this->save($e, '2026-01-01', 'annual');
        $this->balance($e, 2025)->assertJsonPath('data.year', 2025)->assertJsonPath('data.balance.used', 1);
        $this->balance($e, 2026)->assertJsonPath('data.balance.used', 1);
        $this->balance($e, 1999)->assertStatus(422)->assertJsonPath('error.details.0.field', 'year');
    }

    public function test_the_year_adjustment_overrides_the_entitlement_and_adds_opening_days(): void
    {
        $e = $this->employee('office', '2026-07-01');
        $this->balance($e)->assertJsonPath('data.balance.entitlement', 5);
        DB::table('leave_year_adjustments')->insert([
            'id' => (string) Str::uuid7(), 'employee_id' => $e, 'year' => 2026, 'entitlement' => 8, 'opening_used' => 2, 'opening_casual_used' => 1,
            'reason' => 'Reason-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->balance($e)->assertJsonPath('data.balance.entitlement', 8)->assertJsonPath('data.balance.remaining', 6)
            ->assertJsonPath('data.balance.casual_remaining', 5)
            ->assertJsonPath('data.adjustment', ['entitlement' => 8, 'opening_used' => 2, 'opening_casual_used' => 1, 'reason' => 'Reason-1', 'version' => 1]);
    }

    public function test_no_hire_date_leaves_the_entitlement_unknown(): void
    {
        $e = $this->employee('office', '2026-01-01', ['hire_date' => null]);
        $this->balance($e)->assertOk()->assertJsonPath('data.hire_date', null)
            ->assertJsonPath('data.balance.entitlement', null)->assertJsonPath('data.balance.remaining', null);
    }

    public function test_a_day_laborer_has_no_balance(): void
    {
        $e = $this->employee('day_laborer', '2020-01-01');
        $this->balance($e)->assertOk()->assertJsonPath('data.type', 'day_laborer')->assertJsonPath('data.balance', null);
    }

    public function test_an_unknown_employee_is_404(): void
    {
        $this->balance((string) Str::uuid7())->assertNotFound();
    }

    public function test_a_manager_reads_only_employees_in_reach(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $other = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        app(EmployeeService::class)->assignManager($mine, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);

        $this->actingAs($manager, 'api');
        $this->balance($mine)->assertOk()->assertJsonPath('data.balance.entitlement', 21);
        $this->balance($other)->assertNotFound();
    }

    public function test_reading_needs_leave_view(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->signIn('ceo');
        $this->balance($e)->assertOk();
        $this->actingAs($this->userWith('all', 'all'), 'api');   // attendance only
        $this->balance($e)->assertForbidden();
    }

    /** @param  array<string, mixed>  $body */
    private function adjust(string $employeeId, array $body, int $year = 2026): TestResponse
    {
        return $this->putJson("/api/v1/employees/{$employeeId}/leave/{$year}", $body);
    }

    /** @return list<string> the listed employee ids */
    private function listed(string $query = ''): array
    {
        return $this->getJson('/api/v1/leave/balances'.$query)->assertOk()->json('data.*.id');
    }

    public function test_the_list_shows_each_monthly_employees_balance_with_the_adjustment_applied(): void
    {
        $e = $this->employee('office', '2026-07-01');
        $this->save($e, '2026-08-03', 'annual');
        $this->save($e, '2026-08-04', 'casual');
        $this->adjust($e, ['entitlement' => 8, 'opening_used' => 2, 'opening_casual_used' => 1, 'reason' => 'Reason-1'])->assertOk()
            ->assertJsonPath('data.balance.entitlement', 8)->assertJsonPath('data.balance.used', 4)->assertJsonPath('data.balance.remaining', 4)
            ->assertJsonPath('data.balance.casual_used', 2)->assertJsonPath('data.balance.casual_remaining', 4)
            ->assertJsonPath('data.adjustment.version', 1);

        $this->getJson('/api/v1/leave/balances')->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1, 'has_next_page' => false, 'has_previous_page' => false])
            ->assertJsonPath('data.0', [
                'id' => $e, 'code' => DB::table('employees')->where('id', $e)->value('code'), 'name_ar' => 'Office-1', 'name_en' => null, 'type' => 'office',
                'hire_date' => '2026-07-01', 'termination_date' => null, 'entitlement' => 8, 'casual_cap' => 6, 'used' => 4, 'casual_used' => 2, 'remaining' => 4,
                'casual_remaining' => 4, 'sick_days' => 0, 'unpaid_days' => 0,
            ]);
        $this->balance($e)->assertJsonPath('data.balance.remaining', 4);
    }

    public function test_the_list_reads_the_requested_year(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->save($e, '2025-06-01', 'annual');
        $this->getJson('/api/v1/leave/balances')->assertJsonPath('data.0.used', 0);
        $this->getJson('/api/v1/leave/balances?year=2025')->assertJsonPath('data.0.used', 1);
        $this->getJson('/api/v1/leave/balances?year=1999')->assertStatus(422)->assertJsonPath('error.details.0.field', 'year');
    }

    public function test_the_list_holds_the_years_monthly_employees_only_and_filters_by_type(): void
    {
        $office = $this->employee('office', '2020-01-01');
        $engineer = $this->employee('engineer', '2020-01-01');
        $worker = $this->employee('permanent_worker', '2020-01-01');
        $this->employee('day_laborer', '2020-01-01');
        $left = $this->employee('office', '2020-01-01', ['termination_date' => '2026-10-19']);
        $leaving = $this->employee('office', '2020-01-01', ['termination_date' => '2026-10-20']);

        $this->assertSame([$office, $engineer, $worker, $leaving], $this->listed());                // $left is archived (owner, 2026-11-11)
        $this->assertSame([$engineer], $this->listed('?type=engineer'));
        $this->assertSame([$worker], $this->listed('?type=permanent_worker'));
        $this->getJson('/api/v1/leave/balances?type=day_laborer')->assertStatus(422);
    }

    public function test_the_list_searches_names_and_the_exact_code(): void
    {
        $a = $this->employee('office', '2020-01-01', ['name_ar' => 'Alpha-1', 'name_en' => 'First_1']);
        $b = $this->employee('engineer', '2020-01-01', ['name_ar' => 'Beta-1']);
        $code = (int) DB::table('employees')->where('id', $b)->value('code');

        $this->assertSame([$a], $this->listed('?q=alpha'));
        $this->assertSame([$a], $this->listed('?q=first_'));
        $this->assertSame([], $this->listed('?q=%25'));
        $this->assertSame([$b], $this->listed("?q={$code}"));
    }

    public function test_the_list_paginates(): void
    {
        $ids = [$this->employee('office', '2020-01-01'), $this->employee('office', '2020-01-01'), $this->employee('office', '2020-01-01')];
        $this->getJson('/api/v1/leave/balances?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.*.id', [$ids[2]])->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.pagination.has_previous_page', true);
        $this->getJson('/api/v1/leave/balances?per_page=101')->assertStatus(422);
    }

    public function test_a_managers_list_holds_only_employees_in_reach_and_a_manager_cannot_adjust(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $this->employee('engineer', '2020-01-01');
        $this->employee('office', '2020-01-01');
        $manager = $this->makeUser('manager');
        app(EmployeeService::class)->assignManager($mine, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);

        $this->actingAs($manager, 'api');
        $this->assertSame([$mine], $this->listed());
        $this->adjust($mine, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])->assertForbidden();
        $this->assertSame(0, DB::table('leave_year_adjustments')->count());
    }

    public function test_the_ceo_reads_balances_but_cannot_adjust(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->signIn('ceo');
        $this->assertSame([$e], $this->listed());
        $this->balance($e)->assertOk();
        $this->adjust($e, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])->assertForbidden();
        $this->actingAs($this->userWith('all', 'all'), 'api');
        $this->getJson('/api/v1/leave/balances')->assertForbidden();
    }

    public function test_a_stale_or_missing_version_is_a_conflict(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $body = ['entitlement' => null, 'opening_used' => 1, 'opening_casual_used' => 0, 'reason' => 'Reason-1'];
        $this->adjust($e, $body)->assertOk()->assertJsonPath('data.adjustment.version', 1);
        $this->adjust($e, [...$body, 'opening_used' => 2, 'version' => 1])->assertOk()->assertJsonPath('data.adjustment.version', 2);

        $this->adjust($e, [...$body, 'opening_used' => 3, 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->adjust($e, [...$body, 'opening_used' => 3])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->balance($e)->assertJsonPath('data.adjustment.opening_used', 2);

        $fresh = $this->employee('office', '2020-01-01');
        $this->adjust($fresh, [...$body, 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.message', self::CONFLICT_MESSAGE['en']);
        $this->balance($fresh)->assertJsonPath('data.adjustment', null);
    }

    public function test_a_racing_create_is_a_conflict(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced, $e): void {
            if (! $raced && str_starts_with($q->sql, 'select') && str_contains($q->sql, '"leave_year_adjustments"')) {
                $raced = true;
                DB::table('leave_year_adjustments')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $e, 'year' => 2026, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->adjust($e, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->assertTrue($raced);
    }

    public function test_a_non_default_value_needs_a_reason(): void
    {
        $e = $this->employee('office', '2020-01-01');
        foreach ([['entitlement' => 0], ['opening_used' => 1], ['opening_used' => 1, 'opening_casual_used' => 1]] as $set) {
            $this->adjust($e, ['entitlement' => null, 'opening_used' => 0, 'opening_casual_used' => 0, ...$set])
                ->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');
        }
        $this->adjust($e, ['entitlement' => null, 'opening_used' => 0, 'opening_casual_used' => 0])->assertOk()
            ->assertJsonPath('data.balance.entitlement', 21);
    }

    public function test_the_values_are_checked(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $ok = ['entitlement' => null, 'opening_used' => 1, 'opening_casual_used' => 1, 'reason' => 'Reason-1'];
        foreach ([
            'opening_casual_used' => ['opening_casual_used' => 2],
            'entitlement' => ['entitlement' => -1],
            'opening_used' => ['opening_used' => -1],
            'reason' => ['reason' => str_repeat('x', 1001)],
        ] as $field => $bad) {
            $this->adjust($e, [...$ok, ...$bad])->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
        }
        $this->adjust($e, $ok, 1999)->assertStatus(422)->assertJsonPath('error.details.0.field', 'year');
        $this->assertSame(0, DB::table('leave_year_adjustments')->count());
    }

    public function test_a_day_laborer_cannot_be_adjusted(): void
    {
        $e = $this->employee('day_laborer', '2020-01-01');
        $this->adjust($e, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])
            ->assertStatus(422)->assertJsonPath('error', ['code' => 'no_leave_balance', 'message' => __('errors.no_leave_balance')]);
        $this->adjust((string) Str::uuid7(), ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])->assertNotFound();
        $this->assertSame(0, DB::table('leave_year_adjustments')->count());
    }

    public function test_unknown_body_keys_are_ignored(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $other = $this->employee('office', '2020-01-01');
        $this->adjust($e, [
            'entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1',
            'employee_id' => $other, 'year' => 2030, 'deleted_at' => '2026-01-01 00:00:00', 'id' => (string) Str::uuid7(), 'created_by' => $other,
        ])->assertOk();
        $row = DB::table('leave_year_adjustments')->sole();
        $this->assertSame([$e, 2026, null, 1], [$row->employee_id, $row->year, $row->deleted_at, $row->version]);
    }

    public function test_the_adjustment_is_audited_with_before_and_after_values(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->adjust($e, ['entitlement' => 30, 'opening_used' => 1, 'opening_casual_used' => 0, 'reason' => 'Reason-1'])->assertOk();
        $this->adjust($e, ['entitlement' => 25, 'opening_used' => 1, 'opening_casual_used' => 0, 'reason' => 'Reason-2', 'version' => 1])->assertOk();

        $rows = DB::table('audit_log')->where('entity_type', 'leave_year_adjustments')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED'], $rows->pluck('event')->all());
        $created = json_decode($rows[0]->new_values, true);
        $this->assertSame([$e, 2026, 30, 1, 0, 'Reason-1', 1], [$created['employee_id'], $created['year'], $created['entitlement'], $created['opening_used'], $created['opening_casual_used'], $created['reason'], $created['version']]);
        $this->assertEquals(['entitlement' => 30, 'reason' => 'Reason-1', 'version' => 1], json_decode($rows[1]->old_values, true));
        $this->assertEquals(['entitlement' => 25, 'reason' => 'Reason-2', 'version' => 2], json_decode($rows[1]->new_values, true));
    }

    public function test_last_years_list_leaves_out_an_employee_who_left_and_their_leave_tab_keeps_the_balance(): void
    {
        $left = $this->employee('office', '2020-01-01', ['termination_date' => '2025-06-30']);
        $this->save($left, '2025-03-02', 'annual');
        app(EmployeeService::class)->addTypeChange($left, ['type' => 'day_laborer', 'effective_from' => '2025-09-01']);

        $this->getJson('/api/v1/leave/balances?year=2025')->assertOk()->assertJsonPath('data', []);   // archived (owner, 2026-11-11)
        $this->balance($left, 2025)->assertOk()->assertJsonPath('data.type', 'office')->assertJsonPath('data.balance.entitlement', 21)
            ->assertJsonPath('data.balance.used', 1)->assertJsonPath('data.balance.remaining', 20);
        $this->assertSame([], $this->listed());
    }

    public function test_the_type_in_force_at_the_end_of_the_year_decides_the_list_and_the_leave_tab(): void
    {
        $becameMonthly = $this->employee('day_laborer', '2020-01-01');
        $service = app(EmployeeService::class);
        $service->addTypeChange($becameMonthly, ['type' => 'engineer', 'effective_from' => '2025-06-26']);
        $service->addTypeChange($becameMonthly, ['type' => 'day_laborer', 'effective_from' => '2026-03-01']);
        $becameDaily = $this->employee('office', '2020-01-01');
        $service->addTypeChange($becameDaily, ['type' => 'day_laborer', 'effective_from' => '2025-07-01']);
        $service->addTypeChange($becameDaily, ['type' => 'office', 'effective_from' => '2026-03-01']);

        $this->assertSame([$becameMonthly], $this->listed('?year=2025'));
        $this->assertSame([$becameDaily], $this->listed());
        $this->balance($becameMonthly, 2025)->assertJsonPath('data.type', 'engineer')->assertJsonPath('data.balance.entitlement', 21);
        $this->balance($becameDaily, 2025)->assertJsonPath('data.type', 'day_laborer')->assertJsonPath('data.balance', null);
        $this->adjust($becameDaily, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'], 2025)
            ->assertStatus(422)->assertJsonPath('error.code', 'no_leave_balance');
        $this->adjust($becameMonthly, ['entitlement' => 30, 'opening_used' => 0, 'opening_casual_used' => 0, 'reason' => 'Reason-1'], 2025)->assertOk();
    }

    public function test_the_list_leaves_out_employees_not_employed_during_the_year(): void
    {
        $this->cairo('2024-12-31 12:00');                                                           // nobody is archived yet: the year window alone decides
        $this->employee('office', '2026-02-01');
        $this->employee('office', '2020-01-01', ['termination_date' => '2024-12-31']);
        $stayed = $this->employee('office', '2020-01-01', ['termination_date' => '2025-01-01']);

        $this->assertSame([$stayed], $this->listed('?year=2025'));
    }

    public function test_a_future_hire_this_year_is_listed_with_the_hired_during_the_year_entitlement_and_the_leave_tab_agrees(): void
    {
        $future = $this->employee('office', '2026-11-01');

        $this->getJson('/api/v1/leave/balances')->assertOk()->assertJsonPath('data.*.id', [$future])
            ->assertJsonPath('data.0.type', 'office')->assertJsonPath('data.0.entitlement', 1)->assertJsonPath('data.0.remaining', 1);
        $this->balance($future)->assertJsonPath('data.type', 'office')
            ->assertJsonPath('data.balance.entitlement', 1)->assertJsonPath('data.balance.remaining', 1);
    }

    public function test_a_hire_on_dec_31_is_in_that_years_list(): void
    {
        $last = $this->employee('office', '2026-12-31');

        $this->assertSame([$last], $this->listed());
        $this->assertSame([], $this->listed('?year=2025'));
    }

    public function test_a_list_row_carries_the_termination_date(): void
    {
        $this->employee('office', '2020-01-01');
        $this->employee('office', '2020-01-01', ['termination_date' => '2026-10-20']);              // today: still listed

        $this->getJson('/api/v1/leave/balances')->assertOk()->assertJsonPath('data.*.termination_date', [null, '2026-10-20']);
    }

    public function test_a_managers_list_for_last_year_holds_only_employees_in_reach_today(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $was = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $service = app(EmployeeService::class);
        $service->assignManager($mine, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);
        $service->assignManager($was, ['manager_id' => $manager->id, 'effective_from' => '2025-01-01']);
        $service->assignManager($was, ['manager_id' => $this->makeUser('manager')->id, 'effective_from' => '2026-01-01']);

        $this->actingAs($manager, 'api');
        $this->assertSame([$mine], $this->listed('?year=2025'));
    }

    public function test_the_list_runs_the_same_queries_for_one_or_twenty_employees(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/leave/balances')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $first = $this->employee('engineer', '2020-01-01');
        $this->save($first, '2026-03-02', 'annual');
        $queries();   // warm the caches
        $one = $queries();
        foreach (range(1, 19) as $i) {
            $e = $this->employee($i % 2 === 0 ? 'office' : 'engineer', '2020-01-01');
            $this->save($e, '2026-03-02', 'casual');
        }
        $this->assertCount(20, $this->listed());

        $this->assertSame($one, $queries());
    }
}
