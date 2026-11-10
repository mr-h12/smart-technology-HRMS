<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Filing a leave request (leave spec, Counting a request's days and Workflow Submit). 2026-11-06 is a Friday, 11-07 a Saturday. */
class LeaveRequestsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var list<User> */
    private array $hr;

    private User $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->hr = [$this->makeUser('hr'), $this->makeUser('hr')];
        $this->ceo = $this->makeUser('ceo');
        $this->actingAs($this->hr[0], 'api');
    }

    private function preview(string $employeeId, string $from, string $to, string $kind = 'annual'): TestResponse
    {
        return $this->getJson("/api/v1/leave/requests/preview?employee_id={$employeeId}&kind={$kind}&from_date={$from}&to_date={$to}");
    }

    private function submit(string $employeeId, string $from, string $to, string $kind = 'annual', ?string $note = null): TestResponse
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $employeeId, 'kind' => $kind, 'from_date' => $from, 'to_date' => $to, 'note' => $note]);
    }

    private function assign(string $employeeId, User $manager, string $from = '2026-01-01'): void
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => $from]);
    }

    /** @param  array<string, mixed>  $body */
    private function decide(string $id, string $action, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/leave/requests/{$id}/{$action}", ['version' => DB::table('leave_requests')->where('id', $id)->value('version'), ...$body]);
    }

    /** @return array<string, string> date => "leave_code/source" of the employee's stored days */
    private function stored(string $employeeId): array
    {
        return DB::table('attendance_days')->join('sites', 'sites.id', '=', 'attendance_days.site_id')
            ->where('employee_id', $employeeId)->whereNull('attendance_days.deleted_at')->orderBy('date')->get(['date', 'leave_code', 'source'])
            ->mapWithKeys(fn (object $r) => [substr((string) $r->date, 0, 10) => "{$r->leave_code}/{$r->source}"])->all();
    }

    /** @return list<string> */
    private function dates(string $employeeId, string $from, string $to): array
    {
        return $this->preview($employeeId, $from, $to)->assertOk()->json('data.dates');
    }

    public function test_an_engineers_friday_is_not_counted(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->assertSame(['2026-11-05', '2026-11-07', '2026-11-08'], $this->dates($e, '2026-11-05', '2026-11-08'));
    }

    public function test_office_staff_skip_friday_and_saturday(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->assertSame(['2026-11-05', '2026-11-08'], $this->dates($e, '2026-11-05', '2026-11-08'));
    }

    public function test_an_official_holiday_is_not_counted(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->holiday('2026-11-04');
        $this->assertSame(['2026-11-03', '2026-11-05'], $this->dates($e, '2026-11-03', '2026-11-05'));
    }

    public function test_no_day_of_a_holiday_period_is_counted(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->holiday('2026-11-03', 'Holiday-1', '2026-11-05');
        $this->assertSame(['2026-11-02', '2026-11-07', '2026-11-08'], $this->dates($e, '2026-11-02', '2026-11-08'));   // 11-06 is the Friday
        $this->submit($e, '2026-11-02', '2026-11-08')->assertCreated()->assertJsonPath('data.days', 3);
    }

    public function test_dates_after_termination_are_not_counted(): void
    {
        $e = $this->employee('engineer', '2020-01-01', ['termination_date' => '2026-11-04']);
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04'], $this->dates($e, '2026-11-02', '2026-11-08'));
    }

    public function test_a_request_of_one_friday_is_refused_and_nothing_is_stored(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->preview($e, '2026-11-06', '2026-11-06')->assertStatus(422)->assertJsonPath('error.code', 'leave_no_days');
        $this->submit($e, '2026-11-06', '2026-11-06')->assertStatus(422)->assertJsonPath('error.code', 'leave_no_days');
        $this->assertSame(0, DB::table('leave_requests')->count());
    }

    public function test_a_day_laborer_cannot_request_leave(): void
    {
        $e = $this->employee('day_laborer', '2020-01-01');
        $this->preview($e, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'leave_day_laborer');
        $this->submit($e, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'leave_day_laborer')
            ->assertJsonPath('error.message', 'Day laborers have no leave balance and cannot request leave.');
    }

    /** @return array<string, array{string, bool}> status of the first request => whether it still holds its dates */
    public static function statuses(): array
    {
        return ['pending' => ['pending', true], 'escalated' => ['escalated', true], 'approved' => ['approved', true], 'refused' => ['refused', false], 'cancelled' => ['cancelled', false]];
    }

    #[DataProvider('statuses')]
    public function test_an_overlap_is_refused_only_while_the_other_request_is_live(string $status, bool $blocks): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $first = $this->submit($e, '2026-11-02', '2026-11-04')->assertCreated()->json('data.id');
        DB::table('leave_requests')->where('id', $first)->update(['status' => $status]);

        if ($blocks) {
            $this->preview($e, '2026-11-04', '2026-11-05')->assertStatus(422)->assertJsonPath('error.code', 'leave_overlap');
            $this->submit($e, '2026-11-04', '2026-11-05')->assertStatus(422)->assertJsonPath('error.code', 'leave_overlap');
        } else {
            $this->preview($e, '2026-11-04', '2026-11-05')->assertOk();
            $this->submit($e, '2026-11-04', '2026-11-05')->assertCreated();
        }
    }

    public function test_a_permanent_worker_skips_only_friday_and_their_sites_manager_files_it(): void
    {
        $site = $this->workSite();
        $w = $this->employee('permanent_worker', '2020-01-01');
        $this->homeSite($w, $site, '2026-01-01');

        $this->actingAs($this->managerOf($site), 'api');
        $this->assertSame(['2026-11-05', '2026-11-07', '2026-11-08'], $this->dates($w, '2026-11-05', '2026-11-08'));
        $this->submit($w, '2026-11-05', '2026-11-08')->assertCreated()->assertJsonPath('data.days', 3);
    }

    public function test_saturday_stops_counting_from_a_change_to_office(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        app(EmployeeService::class)->addTypeChange($e, ['type' => 'office', 'effective_from' => '2026-11-01']);
        $this->assertSame(
            ['2026-10-29', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-08'],
            $this->dates($e, '2026-10-29', '2026-11-08'),
        );
    }

    public function test_a_range_crossing_dec_31_is_refused(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->submit($e, '2026-12-30', '2027-01-03')->assertStatus(422)->assertJsonPath('error.code', 'leave_crosses_year');
    }

    public function test_a_range_is_at_most_60_days(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->preview($e, '2026-11-01', '2026-12-30')->assertOk();
        $this->submit($e, '2026-11-01', '2026-12-31')->assertStatus(422)->assertJsonPath('error.code', 'leave_too_long');
    }

    public function test_the_input_is_validated(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->submit($e, '2026-11-05', '2026-11-04')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->submit($e, '2026-11-04', '2026-11-05', 'holiday')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->preview($e, '2026-11-04', 'tomorrow')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_managers_request_is_pending_and_notifies_each_hr_user_once(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);

        $this->actingAs($manager, 'api');
        $row = $this->submit($e, '2026-11-05', '2026-11-08', 'annual', 'Note-1')->assertCreated()
            ->assertJsonPath('data.employee.id', $e)->assertJsonPath('data.employee.name_ar', 'Engineer-1')
            ->assertJsonPath('data.kind', 'annual')->assertJsonPath('data.from_date', '2026-11-05')->assertJsonPath('data.to_date', '2026-11-08')
            ->assertJsonPath('data.days', 3)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.note', 'Note-1')
            ->assertJsonPath('data.requested_by', ['id' => $manager->id, 'name' => 'manager'])->assertJsonPath('data.version', 1)
            ->json('data');
        $this->assertSame(['id', 'employee', 'kind', 'from_date', 'to_date', 'days', 'status', 'note', 'requested_by', 'created_at', 'version'], array_keys($row));

        $this->assertEqualsCanonicalizing([$this->hr[0]->id, $this->hr[1]->id], Notification::pluck('user_id')->all());
        foreach (Notification::all() as $n) {
            $this->assertSame(['leave_requested', '2026-10-20', $row['id']], [$n->type, substr((string) $n->date, 0, 10), $n->ref]);
            $this->assertEquals(['employee_id' => $e, 'request_id' => $row['id'], 'kind' => 'annual', 'from_date' => '2026-11-05', 'to_date' => '2026-11-08', 'days' => 3], $n->data);
        }
    }

    public function test_two_requests_the_same_day_send_two_notifications_per_hr_user(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);

        $this->actingAs($manager, 'api');
        $this->submit($e, '2026-11-02', '2026-11-03')->assertCreated();
        $this->submit($e, '2026-11-09', '2026-11-10', 'casual')->assertCreated();

        $this->assertSame(2, Notification::where('user_id', $this->hr[0]->id)->count());
        $this->assertSame(2, Notification::where('user_id', $this->hr[1]->id)->count());
    }

    public function test_hrs_own_request_notifies_nobody(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->submit($e, '2026-11-02', '2026-11-03')->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by.id', $this->hr[0]->id);
        $this->assertSame(0, Notification::count());
    }

    public function test_the_request_is_audited(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');
        $audit = DB::table('audit_log')->where(['entity_type' => 'leave_requests', 'entity_id' => $id])->get();
        $this->assertSame(['CREATED'], $audit->pluck('event')->all());
        $this->assertSame('pending', json_decode((string) $audit[0]->new_values, true)['status']);
    }

    public function test_a_manager_is_refused_when_a_counted_date_is_out_of_reach(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $this->assign($e, $this->makeUser('manager'), '2026-11-05');

        $this->actingAs($manager, 'api');
        $this->preview($e, '2026-11-03', '2026-11-05')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach')
            ->assertJsonPath('error.message', 'This employee is not in your reach on some of these dates.');
        $this->submit($e, '2026-11-03', '2026-11-05')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');
        $this->submit($e, '2026-11-03', '2026-11-04')->assertCreated();
        $this->assertSame(1, DB::table('leave_requests')->count());
    }

    public function test_a_manager_is_refused_until_the_employee_is_in_reach_today(): void
    {
        $site = $this->workSite();
        $manager = $this->managerOf($site);
        $engineer = $this->employee('engineer', '2020-01-01');
        $this->assign($engineer, $manager, '2026-11-01');
        $worker = $this->employee('permanent_worker', '2020-01-01');
        $this->homeSite($worker, $site, '2026-11-01');

        $this->actingAs($manager, 'api');
        foreach ([$engineer, $worker] as $e) {
            $this->preview($e, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach')
                ->assertJsonMissingPath('data.balance');
            $this->submit($e, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');
        }
        $this->assertSame(0, DB::table('leave_requests')->count());
    }

    public function test_the_reach_refusal_has_its_own_arabic_text(): void
    {
        $office = $this->employee('office', '2020-01-01');
        $this->actingAs($this->makeUser('manager'), 'api');
        $this->withHeader('Accept-Language', 'ar')->submit($office, '2026-11-02', '2026-11-03')->assertStatus(422)
            ->assertJsonPath('error.message', 'هذا الموظف خارج نطاقك في بعض هذه الأيام.');
    }

    public function test_a_manager_cannot_file_for_office_staff_or_learn_about_an_employee_out_of_reach(): void
    {
        $office = $this->employee('office', '2020-01-01');
        $laborer = $this->employee('day_laborer', '2020-01-01');
        $this->actingAs($this->makeUser('manager'), 'api');
        $this->submit($office, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');
        $this->submit($laborer, '2026-11-02', '2026-11-03')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');
        $this->preview($office, '2026-11-06', '2026-11-07')->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');
    }

    public function test_a_termination_inside_the_range_drops_the_later_dates_for_a_manager_too(): void
    {
        $e = $this->employee('engineer', '2020-01-01', ['termination_date' => '2026-11-03']);
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $this->actingAs($manager, 'api');
        $this->submit($e, '2026-11-02', '2026-11-05')->assertCreated()->assertJsonPath('data.days', 2);
    }

    public function test_requests_need_leave_request(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->actingAs(User::where('role_id', DB::table('roles')->where('slug', 'ceo')->value('id'))->firstOrFail(), 'api');
        $this->submit($e, '2026-11-02', '2026-11-03')->assertForbidden();
        $this->preview($e, '2026-11-02', '2026-11-03')->assertForbidden();
    }

    public function test_the_preview_splits_annual_days_beyond_the_balance_into_unpaid(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $days = app(AttendanceDays::class);
        $annual = DB::table('sites')->where(['kind' => 'leave', 'leave_code' => 'annual', 'active' => true])->value('id');
        $days->save($e, '2026-10-01', ['site_id' => $annual]);
        $days->save($e, '2026-10-03', ['site_id' => $annual]);

        $this->preview($e, '2026-11-02', '2026-11-08')->assertOk()
            ->assertJsonPath('data.days', 6)->assertJsonPath('data.balance.remaining', 3)
            ->assertJsonPath('data.keep', 3)->assertJsonPath('data.unpaid', 3)->assertJsonPath('data.remaining_after', 0);
    }

    public function test_casual_keeps_within_the_smaller_balance_and_sick_never_splits(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $casual = DB::table('sites')->where(['kind' => 'leave', 'leave_code' => 'casual', 'active' => true])->value('id');
        foreach (['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $casual]);
        }

        $this->preview($e, '2026-11-02', '2026-11-05', 'casual')->assertOk()
            ->assertJsonPath('data.days', 4)->assertJsonPath('data.keep', 2)->assertJsonPath('data.unpaid', 2)->assertJsonPath('data.remaining_after', 15);
        $this->preview($e, '2026-11-02', '2026-11-05', 'sick')->assertOk()
            ->assertJsonPath('data.keep', 4)->assertJsonPath('data.unpaid', 0)->assertJsonPath('data.remaining_after', 17);
    }

    public function test_no_hire_date_keeps_every_day_with_no_remaining_after(): void
    {
        $e = $this->employee('engineer', '2020-01-01', ['hire_date' => null]);
        $this->preview($e, '2026-11-02', '2026-11-03')->assertOk()
            ->assertJsonPath('data.balance.entitlement', null)->assertJsonPath('data.keep', 2)->assertJsonPath('data.unpaid', 0)
            ->assertJsonPath('data.remaining_after', null);
    }

    public function test_the_list_puts_pending_and_escalated_first_then_newest(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $old = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');
        $this->travel(1)->minutes();
        $decided = $this->submit($e, '2026-11-09', '2026-11-10')->json('data.id');
        $this->travel(1)->minutes();
        $escalated = $this->submit($e, '2026-11-16', '2026-11-17')->json('data.id');
        DB::table('leave_requests')->where('id', $decided)->update(['status' => 'approved']);
        DB::table('leave_requests')->where('id', $escalated)->update(['status' => 'escalated']);

        $list = $this->getJson('/api/v1/leave/requests')->assertOk();
        $this->assertSame([$escalated, $old, $decided], array_column($list->json('data'), 'id'));
        $this->assertSame(3, $list->json('meta.pagination.total'));
        $this->assertSame([$decided], array_column($this->getJson('/api/v1/leave/requests?status=approved')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/leave/requests?year=2027')->json('data'));
        $this->getJson('/api/v1/leave/requests?per_page=101')->assertStatus(422);
    }

    public function test_a_manager_lists_only_requests_for_employees_in_reach_today(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $other = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($mine, $manager);
        $minesRequest = $this->submit($mine, '2026-11-02', '2026-11-03')->json('data.id');
        $this->submit($other, '2026-11-02', '2026-11-03')->assertCreated();

        $this->actingAs($manager, 'api');
        $this->assertSame([$minesRequest], array_column($this->getJson('/api/v1/leave/requests')->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->getJson("/api/v1/leave/requests?employee_id={$other}")->assertOk()->json('data'));
    }

    public function test_the_inbox_names_only_employees_the_reader_can_view(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $other = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($mine, $manager);
        $notifier = app(Notifier::class);
        foreach ([$mine, $other] as $id) {
            $notifier->notifyOnce($manager->id, 'leave_decided', '2026-10-20', ['employee_id' => $id], $id);
            $notifier->notifyOnce($this->hr[0]->id, 'leave_requested', '2026-10-20', ['employee_id' => $id], $id);
        }
        $blind = $this->userWith('all', null);   // no employee.view
        $notifier->notifyOnce($blind->id, 'leave_requested', '2026-10-20', ['employee_id' => $mine], $mine);

        $this->actingAs($manager, 'api');
        $rows = collect($this->getJson('/api/v1/notifications')->assertOk()->json('data'))->keyBy('ref');
        $this->assertSame($mine, $rows[$mine]['employee']['id']);
        $this->assertNull($rows[$other]['employee']);
        $this->assertNull($this->patchJson('/api/v1/notifications/'.$rows[$other]['id'].'/read')->assertOk()->json('data.employee'));

        $this->actingAs($this->hr[0], 'api');
        $rows = collect($this->getJson('/api/v1/notifications')->json('data'))->keyBy('ref');
        $this->assertSame([$mine, $other], [$rows[$mine]['employee']['id'], $rows[$other]['employee']['id']]);

        $this->actingAs($blind, 'api');
        $this->assertNull($this->getJson('/api/v1/notifications')->json('data.0.employee'));
    }

    public function test_approval_writes_each_counted_date_with_source_leave_and_leaves_friday_alone(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-05', '2026-11-08')->json('data.id');

        $row = $this->decide($id, 'approve')->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.days', 3)->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.decided_by', ['id' => $this->hr[0]->id, 'name' => 'hr'])->assertJsonPath('data.decision_reason', null)
            ->assertJsonPath('data.approval', null)->json('data');
        $this->assertNotNull($row['decided_at']);
        $this->assertSame(['2026-11-05' => 'annual/leave', '2026-11-07' => 'annual/leave', '2026-11-08' => 'annual/leave'], $this->stored($e));
    }

    public function test_annual_days_beyond_the_balance_are_approved_as_unpaid_latest_last(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $annual = $this->leaveTypes()['annual'];
        foreach (['2026-10-01', '2026-10-03', '2026-10-04'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $annual]);
        }
        $id = $this->submit($e, '2026-11-02', '2026-11-05')->json('data.id');

        $this->decide($id, 'approve')->assertOk();
        $this->assertSame(
            ['2026-11-02' => 'annual/leave', '2026-11-03' => 'annual/leave', '2026-11-04' => 'unpaid/leave', '2026-11-05' => 'unpaid/leave'],
            array_slice($this->stored($e), 3),
        );
    }

    public function test_casual_is_kept_within_the_smaller_balance_and_sick_never_splits(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $casual = $this->leaveTypes()['casual'];
        foreach (['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $casual]);
        }
        $this->decide($this->submit($e, '2026-11-02', '2026-11-05', 'casual')->json('data.id'), 'approve')->assertOk();
        $this->assertSame(
            ['2026-11-02' => 'casual/leave', '2026-11-03' => 'casual/leave', '2026-11-04' => 'unpaid/leave', '2026-11-05' => 'unpaid/leave'],
            array_slice($this->stored($e), 4),
        );

        $new = $this->employee('engineer', '2026-11-01');   // entitlement 1
        $this->decide($this->submit($new, '2026-11-02', '2026-11-04', 'sick')->json('data.id'), 'approve')->assertOk();
        $this->assertSame(['2026-11-02' => 'sick/leave', '2026-11-03' => 'sick/leave', '2026-11-04' => 'sick/leave'], $this->stored($new));
    }

    public function test_the_split_leaves_out_leave_days_already_stored_on_the_requests_own_dates(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $annual = $this->leaveTypes()['annual'];
        foreach (['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-12', '2026-10-13'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $annual]);
        }
        $this->preview($e, '2026-10-12', '2026-10-15')->assertOk()
            ->assertJsonPath('data.balance.remaining', 2)->assertJsonPath('data.keep', 2)->assertJsonPath('data.unpaid', 2)->assertJsonPath('data.remaining_after', 0);
        $id = $this->submit($e, '2026-10-12', '2026-10-15')->json('data.id');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()
            ->assertJsonPath('data.approval.balance.remaining', 2)->assertJsonPath('data.approval.keep', 2)->assertJsonPath('data.approval.remaining_after', 0);

        $this->decide($id, 'approve')->assertOk();
        $this->assertSame(
            ['2026-10-12' => 'annual/entered', '2026-10-13' => 'annual/entered', '2026-10-14' => 'unpaid/leave', '2026-10-15' => 'unpaid/leave'],
            array_slice($this->stored($e), 3),
        );

        $c = $this->employee('engineer', '2020-01-01');
        foreach (['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-12'] as $date) {
            app(AttendanceDays::class)->save($c, $date, ['site_id' => $this->leaveTypes()['casual']]);
        }
        $this->preview($c, '2026-10-12', '2026-10-14', 'casual')->assertOk()
            ->assertJsonPath('data.balance.casual_remaining', 2)->assertJsonPath('data.keep', 2)->assertJsonPath('data.unpaid', 1);
    }

    public function test_a_worked_day_blocks_approval_names_the_date_and_writes_nothing(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-15')->json('data.id');
        $days = app(AttendanceDays::class);
        $days->save($e, '2026-10-13', ['site_id' => $this->workSite()]);
        $days->save($e, '2026-10-14', ['site_id' => $this->leaveTypes()['sick']]);   // a leave day is replaced, not a block

        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.approval.blocked', ['2026-10-13']);
        $this->decide($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'leave_days_blocked')
            ->assertJsonPath('error.dates', ['2026-10-13'])->assertJsonPath('error.message', 'Requested days that already hold a work day or are in a locked period: 1.');
        $this->withHeader('Accept-Language', 'ar')->decide($id, 'approve')->assertStatus(409)
            ->assertJsonPath('error.message', 'عدد الأيام المحجوزة بحضور مسجّل أو في فترة مقفلة: 1.');
        $this->assertSame(['2026-10-13' => '/entered', '2026-10-14' => 'sick/entered'], $this->stored($e));
        $this->assertSame(['pending', 1], [DB::table('leave_requests')->where('id', $id)->value('status'), DB::table('leave_requests')->where('id', $id)->value('version')]);
    }

    public function test_a_locked_period_blocks_approval(): void
    {
        $e = $this->employee('engineer', '2026-09-26');
        $id = $this->submit($e, '2026-10-12', '2026-10-13')->json('data.id');
        $this->leaveTypes();   // the lock stores the derived Fridays
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();

        $this->decide($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'leave_days_blocked')->assertJsonPath('error.dates', ['2026-10-12', '2026-10-13']);
        $this->assertSame('pending', DB::table('leave_requests')->where('id', $id)->value('status'));
    }

    public function test_a_holiday_added_after_submit_is_not_written_and_days_drops(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-04')->assertJsonPath('data.days', 3)->json('data.id');
        $this->holiday('2026-11-03');

        $this->decide($id, 'approve')->assertOk()->assertJsonPath('data.days', 2);
        $this->assertSame(['2026-11-02' => 'annual/leave', '2026-11-04' => 'annual/leave'], $this->stored($e));

        $only = $this->submit($e, '2026-11-10', '2026-11-10')->json('data.id');
        $this->holiday('2026-11-10', 'Holiday-2');
        $this->decide($only, 'approve')->assertStatus(422)->assertJsonPath('error.code', 'leave_no_days');
    }

    public function test_hr_escalates_with_a_note_and_only_the_ceo_decides_it(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');

        $this->decide($id, 'escalate', ['reason' => 'Note-HR'])->assertOk()->assertJsonPath('data.status', 'escalated')
            ->assertJsonPath('data.decision_reason', 'Note-HR')->assertJsonPath('data.decided_by', null)->assertJsonPath('data.decided_at', null)
            ->assertJsonPath('data.approval.days', 2);
        $n = Notification::sole();
        $this->assertSame([$this->ceo->id, 'leave_escalated', $id], [$n->user_id, $n->type, $n->ref]);
        $this->assertEquals(['employee_id' => $e, 'request_id' => $id, 'kind' => 'annual', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'days' => 2], $n->data);

        $this->decide($id, 'approve')->assertForbidden();
        $this->decide($id, 'refuse', ['reason' => 'R'])->assertForbidden();
        $this->decide($id, 'escalate')->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->actingAs($this->ceo, 'api');
        $this->decide($id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.id', $this->ceo->id);
        $this->assertCount(2, $this->stored($e));
    }

    public function test_the_ceo_cannot_decide_a_pending_request_and_a_manager_decides_nothing(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');

        foreach ([$this->ceo, $manager] as $user) {
            $this->actingAs($user, 'api');
            $this->decide($id, 'approve')->assertForbidden();
            $this->decide($id, 'refuse', ['reason' => 'R'])->assertForbidden();
            $this->decide($id, 'escalate')->assertForbidden();
        }
        $this->assertSame(['pending', 1], [DB::table('leave_requests')->where('id', $id)->value('status'), DB::table('leave_requests')->where('id', $id)->value('version')]);
    }

    public function test_the_escalator_is_not_told_of_their_own_escalation(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $developer = $this->makeUser('developer');
        $this->actingAs($developer, 'api');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');

        $this->decide($id, 'escalate')->assertOk();
        $this->assertSame([$this->ceo->id], Notification::where('type', 'leave_escalated')->pluck('user_id')->all());
    }

    public function test_a_caller_without_the_decide_permission_never_takes_the_employees_locks(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');
        $locks = 0;
        DB::listen(function ($query) use (&$locks): void {
            $locks += (int) (str_contains($query->sql, 'pg_advisory_xact_lock') || str_contains($query->sql, 'for update'));
        });

        $this->actingAs($this->makeUser('manager'), 'api');
        $this->decide($id, 'approve')->assertForbidden();
        $this->actingAs($this->ceo, 'api');
        $this->decide($id, 'escalate')->assertForbidden();
        $this->assertSame(0, $locks);
    }

    public function test_refusal_needs_a_reason_and_writes_no_day(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');

        $this->decide($id, 'refuse')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->decide($id, 'refuse', ['reason' => str_repeat('x', 1001)])->assertStatus(422);
        $this->decide($id, 'refuse', ['reason' => 'Reason-1'])->assertOk()->assertJsonPath('data.status', 'refused')
            ->assertJsonPath('data.decision_reason', 'Reason-1')->assertJsonPath('data.decided_by.id', $this->hr[0]->id)->assertJsonPath('data.approval', null);
        $this->assertSame([], $this->stored($e));
    }

    public function test_a_stale_version_or_a_decided_request_is_a_conflict(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');

        $this->decide($id, 'approve', ['version' => 7])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->decide($id, 'approve')->assertOk();
        $this->decide($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->decide($id, 'refuse', ['reason' => 'R'])->assertStatus(409);
        $this->decide($id, 'escalate')->assertStatus(409);
        $this->actingAs($this->ceo, 'api');
        foreach (['approve', 'refuse', 'escalate'] as $action) {
            $this->decide($id, $action, $action === 'refuse' ? ['reason' => 'R'] : [])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        }
        $this->actingAs($this->hr[0], 'api');
        $this->decide($id, 'approve', ['version' => null])->assertStatus(422);
        $this->postJson('/api/v1/leave/requests/'.Str::uuid7().'/approve', ['version' => 1])->assertNotFound();
    }

    public function test_the_requester_hears_the_decision_unless_they_decided_it(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $this->actingAs($manager, 'api');
        $approved = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');
        $refused = $this->submit($e, '2026-11-09', '2026-11-10')->json('data.id');
        $this->actingAs($this->hr[0], 'api');
        Notification::query()->delete();

        $this->decide($approved, 'approve')->assertOk();
        $this->decide($refused, 'refuse', ['reason' => 'R'])->assertOk();
        $rows = Notification::where('user_id', $manager->id)->get()->keyBy('ref');
        $this->assertSame(['leave_decided', 'leave_decided'], [$rows[$approved]->type, $rows[$refused]->type]);
        $this->assertEquals(['employee_id' => $e, 'request_id' => $approved, 'kind' => 'annual', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'days' => 2, 'status' => 'approved'], $rows[$approved]->data);
        $this->assertSame('refused', $rows[$refused]->data['status']);
        $this->assertSame(2, Notification::count());

        $own = $this->submit($e, '2026-11-16', '2026-11-17')->json('data.id');
        $this->decide($own, 'approve')->assertOk();
        $this->assertSame(2, Notification::count());
    }

    public function test_every_transition_is_audited(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-11-02', '2026-11-03')->json('data.id');
        $this->decide($id, 'escalate');
        $this->actingAs($this->ceo, 'api');
        $this->decide($id, 'refuse', ['reason' => 'R']);

        $audit = DB::table('audit_log')->where(['entity_type' => 'leave_requests', 'entity_id' => $id])->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED', 'UPDATED'], $audit->pluck('event')->all());
        $this->assertSame(['pending', 'escalated'], [json_decode((string) $audit[1]->old_values, true)['status'], json_decode((string) $audit[1]->new_values, true)['status']]);
        $this->assertSame(['escalated', 'refused'], [json_decode((string) $audit[2]->old_values, true)['status'], json_decode((string) $audit[2]->new_values, true)['status']]);

        $this->actingAs($this->hr[0], 'api');
        $approved = $this->submit($e, '2026-11-09', '2026-11-10')->json('data.id');
        $this->decide($approved, 'approve')->assertOk();
        $update = DB::table('audit_log')->where(['entity_type' => 'leave_requests', 'entity_id' => $approved, 'event' => 'UPDATED'])->sole();
        $this->assertSame(['pending', 'approved'], [json_decode((string) $update->old_values, true)['status'], json_decode((string) $update->new_values, true)['status']]);
        $days = DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'CREATED'])->pluck('new_values')
            ->map(fn ($v) => json_decode((string) $v, true))->filter(fn (array $v) => $v['employee_id'] === $e)->values();
        $this->assertSame([['2026-11-09', 'leave'], ['2026-11-10', 'leave']], $days->map(fn (array $v) => [substr((string) $v['date'], 0, 10), $v['source']])->sortBy(0)->values()->all());
    }

    public function test_show_carries_the_approval_preview_while_pending_and_only_within_reach(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $id = $this->submit($e, '2026-11-02', '2026-11-08', 'annual', 'Note-1')->json('data.id');

        $data = $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.note', 'Note-1')
            ->assertJsonPath('data.decided_by', null)->assertJsonPath('data.decision_reason', null)->json('data');
        $this->assertSame(['dates', 'days', 'balance', 'keep', 'unpaid', 'remaining_after', 'blocked', 'replaced'], array_keys($data['approval']));
        $this->assertSame([6, 5, 1, 0, []], [$data['approval']['days'], $data['approval']['keep'], $data['approval']['unpaid'], $data['approval']['remaining_after'], $data['approval']['blocked']]);
        $this->assertSame(5, $data['approval']['balance']['remaining']);

        $this->actingAs($manager, 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk();
        $this->actingAs($this->makeUser('manager'), 'api');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertNotFound();
        $this->getJson('/api/v1/leave/requests/'.Str::uuid7())->assertNotFound();
    }

    private function statusOf(string $id): string
    {
        return (string) DB::table('leave_requests')->where('id', $id)->value('status');
    }

    public function test_approval_lists_days_of_another_leave_type_and_refuses_them_by_default(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $this->leaveTypes()['sick']]);

        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.approval.replaced', [['date' => '2026-10-13', 'leave_code' => 'sick']]);
        $this->decide($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'leave_days_replaced')->assertJsonPath('error.dates', ['2026-10-13'])
            ->assertJsonPath('error.message', 'Requested days that already hold another leave type: 1. Tick "Replace these days", or change the day\'s type in attendance first.');
        $this->withHeader('Accept-Language', 'ar')->decide($id, 'approve')->assertStatus(409)
            ->assertJsonPath('error.message', 'عدد الأيام المسجّلة بنوع إجازة آخر: 1. اختر «استبدال هذه الأيام»، أو غيّر نوع اليوم في الحضور أولًا.');
        $this->assertSame(['pending', 1], [$this->statusOf($id), (int) DB::table('leave_requests')->where('id', $id)->value('version')]);
        $this->assertSame(['2026-10-13' => 'sick/entered'], $this->stored($e));
    }

    public function test_replace_overwrites_the_dates_hr_saw_keeping_note_and_advance(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $this->leaveTypes()['sick'], 'note' => 'Note-1', 'advance_amount' => '100']);

        $this->decide($id, 'approve', ['replace_dates' => ['2026-10-13']])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(['2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/leave', '2026-10-14' => 'annual/leave'], $this->stored($e));
        $day = DB::table('attendance_days')->where(['employee_id' => $e, 'date' => '2026-10-13'])->first();
        $this->assertSame(['Note-1', '100.000000'], [$day?->note, $day?->advance_amount]);
    }

    public function test_a_replaced_date_hr_did_not_see_refuses(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');
        $leave = $this->leaveTypes();
        app(AttendanceDays::class)->save($e, '2026-10-12', ['site_id' => $leave['sick']]);
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $leave['casual']]);

        $this->decide($id, 'approve', ['replace_dates' => ['2026-10-12']])->assertStatus(409)
            ->assertJsonPath('error.code', 'leave_days_replaced')->assertJsonPath('error.dates', ['2026-10-13']);
        $this->assertSame(['2026-10-12' => 'sick/entered', '2026-10-13' => 'casual/entered'], $this->stored($e));
        $this->assertSame('pending', $this->statusOf($id));
    }

    public function test_hrs_same_type_day_is_not_listed_and_keeps_its_source(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $annual = $this->leaveTypes()['annual'];
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $annual]);
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');
        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.approval.replaced', []);
        $this->decide($id, 'approve')->assertOk();
        $this->assertSame(['2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/entered', '2026-10-14' => 'annual/leave'], $this->stored($e));

        // A day on a since-deactivated annual type (same code, another site id) is HR's too.
        $f = $this->employee('engineer', '2020-01-01');
        app(AttendanceDays::class)->save($f, '2026-10-13', ['site_id' => $annual]);
        Site::findOrFail($annual)->update(['active' => false]);
        Site::create(['name_ar' => 'Leave-annual-2', 'kind' => 'leave', 'leave_code' => 'annual']);
        $other = $this->submit($f, '2026-10-12', '2026-10-14')->json('data.id');
        $this->getJson("/api/v1/leave/requests/{$other}")->assertOk()->assertJsonPath('data.approval.replaced', []);
        $this->decide($other, 'approve')->assertOk();
        $this->assertSame(['2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/entered', '2026-10-14' => 'annual/leave'], $this->stored($f));
    }

    public function test_an_annual_day_on_an_overflow_date_is_listed_against_unpaid(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $annual = $this->leaveTypes()['annual'];
        foreach (['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-14'] as $date) {
            app(AttendanceDays::class)->save($e, $date, ['site_id' => $annual]);
        }
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');   // remaining 2: 10-14 is unpaid

        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.approval.keep', 2)
            ->assertJsonPath('data.approval.replaced', [['date' => '2026-10-14', 'leave_code' => 'annual']]);
        $this->decide($id, 'approve')->assertStatus(409)->assertJsonPath('error.code', 'leave_days_replaced')->assertJsonPath('error.dates', ['2026-10-14']);
        $this->assertSame(['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-14'], array_keys($this->stored($e)));
        $this->assertSame('pending', $this->statusOf($id));
    }

    public function test_a_stored_derived_day_is_not_listed(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $this->leaveTypes()['official_holiday']], null, 'derived');

        $this->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.approval.replaced', []);
        $this->decide($id, 'approve')->assertOk();
        $this->assertSame(['2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/leave', '2026-10-14' => 'annual/leave'], $this->stored($e));
    }

    public function test_the_leave_write_refuses_another_leave_type_it_was_not_told_to_replace(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $leave = $this->leaveTypes();
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $leave['sick']]);

        try {
            app(AttendanceDays::class)->writeLeaveDay($e, '2026-10-13', $leave['annual']);
            $this->fail('A day changed to another leave type since the preview must conflict.');
        } catch (ConcurrencyConflict) {
            $this->assertSame(['2026-10-13' => 'sick/entered'], $this->stored($e));
        }
        app(AttendanceDays::class)->writeLeaveDay($e, '2026-10-13', $leave['annual'], true);
        $this->assertSame(['2026-10-13' => 'annual/leave'], $this->stored($e));
    }

    public function test_replace_dates_is_only_for_approve(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14')->json('data.id');

        $this->decide($id, 'refuse', ['reason' => 'R', 'replace_dates' => ['2026-10-12']])->assertStatus(422);
        $this->decide($id, 'escalate', ['replace_dates' => ['2026-10-12']])->assertStatus(422);
        $this->decide($id, 'approve', ['replace_dates' => ['12-10-2026']])->assertStatus(422);
        $this->assertSame('pending', $this->statusOf($id));
    }
}
