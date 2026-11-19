<?php

namespace Tests\Feature;

use App\Http\Middleware\NormalizeDigits;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Settings\Presentation\SettingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/**
 * The site manager's new day laborer and HR's review of its proposed wage, the digits every numeric field takes, and what a site
 * manager may read about the employees they reach (owner, 2026-11-19). Synthetic names and numbers only.
 */
class SiteManagerWorkflowTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s3;

    private User $m;

    private User $other;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->hr = $this->signIn('hr');
        [$this->s1, $this->s3] = [$this->workSite('Site-1'), $this->workSite('Site-3')];
        $this->m = $this->managerOf($this->s1);
        $this->other = $this->managerOf($this->s3);
    }

    public function test_a_new_laborer_needs_a_three_part_name_and_extra_spaces_are_collapsed(): void
    {
        $this->actingAs($this->m, 'api');
        foreach (['سعيد', 'سعيد محمود', '  سعيد   محمود  '] as $short) {
            $this->withHeader('Accept-Language', 'ar')->propose(['name_ar' => $short])->assertStatus(422)
                ->assertJsonFragment(['اكتب الاسم الثلاثي على الأقل: ثلاث كلمات مثل «محمد أحمد علي».']);
        }
        $this->withHeader('Accept-Language', 'en')->propose(['name_ar' => 'Saeed'])->assertStatus(422)
            ->assertJsonFragment(['Enter at least a three-part name: three words, such as “Mohamed Ahmed Ali”.']);
        $made = $this->propose(['name_ar' => "  سعيد \t محمود   علي  "])->assertCreated()->json('data');
        $this->assertSame('سعيد محمود علي', $made['name_ar']);
        $this->assertSame('سعيد محمود علي', DB::table('employees')->where('id', $made['id'])->value('name_ar'));
        $this->assertIsInt($made['code']);
    }

    public function test_the_national_id_is_optional_reads_any_digits_keeps_leading_zeros_and_needs_14(): void
    {
        $this->actingAs($this->m, 'api');
        $this->propose(['name_ar' => 'عامل بدون رقم قومي'])->assertCreated();
        foreach (['0123456789012', '012345678901234', '0123456789012a', '٠١٢٣٤٥٦٧٨٩٠١٢'] as $bad) {   // 13, 15, a letter, 13 Arabic digits
            $this->propose(['name_ar' => 'عامل رقم خطأ', 'national_id' => $bad])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $made = $this->propose(['name_ar' => 'عامل رقم عربي', 'national_id' => '٠١٢٣٤۵۶۷۸۹٠١٢٣'])->assertCreated()->json('data');
        $this->assertSame('01234567890123', DB::table('employees')->where('id', $made['id'])->value('national_id'));
        $this->assertStringNotContainsString('01234567890123', $this->getJson("/api/v1/employees/{$made['id']}")->getContent());
    }

    public function test_hr_reads_the_national_id_and_the_site_manager_never_does(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose(['name_ar' => 'عامل رقم مخفي', 'national_id' => '29901011234567'])->assertCreated()->json('data');
        $this->getJson("/api/v1/employees/{$made['id']}")->assertOk()->assertJsonMissingPath('data.national_id');
        $this->assertStringNotContainsString('29901011234567', $this->getJson('/api/v1/employees')->getContent());
        $this->assertStringNotContainsString('29901011234567', $this->getJson('/api/v1/employees/wage-proposals/mine')->getContent());

        $this->actingAs($this->hr, 'api');
        $this->getJson("/api/v1/employees/{$made['id']}")->assertOk()->assertJsonPath('data.national_id', '29901011234567');
        $this->getJson('/api/v1/employees/wage-proposals')->assertOk()->assertJsonPath('data.0.employee.national_id', '29901011234567');
        // no national ID or wage in HR's notification either
        $data = DB::table('notifications')->where(['user_id' => $this->hr->id, 'type' => 'wage_proposed'])->value('data');
        $this->assertStringNotContainsString('29901011234567', (string) $data);
        $this->assertStringNotContainsString('daily_wage', (string) $data);
    }

    public function test_a_taken_national_id_names_the_worker_only_when_the_manager_reaches_them(): void
    {
        $this->homeSite($mine = $this->employee('day_laborer', '2026-01-01', ['national_id' => '29901011111111']), $this->s1, '2026-10-01');
        $this->homeSite($this->employee('day_laborer', '2026-01-01', ['national_id' => '29901012222222']), $this->s3, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->propose(['name_ar' => 'عامل رقم مكرر', 'national_id' => '29901011111111'])->assertStatus(409)
            ->assertJsonPath('error.code', 'laborer_exists')->assertJsonPath('error.reason', 'national_id')->assertJsonPath('error.match.id', $mine);
        $theirs = $this->withHeader('Accept-Language', 'ar')->propose(['name_ar' => 'عامل رقم آخر', 'national_id' => '29901012222222'])->assertStatus(409)
            ->assertJsonPath('error.reason', 'national_id')->assertJsonPath('error.match', null)
            ->assertJsonPath('error.message', 'هذا الرقم القومي مسجل في النظام. تواصل مع الموارد البشرية.');
        $this->assertStringNotContainsString('Laborer-2', $theirs->getContent());
        $this->assertSame(2, DB::table('employees')->count());
    }

    public function test_a_matching_name_shows_the_match_first_ambiguous_names_say_so_and_confirm_new_creates(): void
    {
        $this->homeSite($same = $this->employee('day_laborer', '2026-01-01', ['name_ar' => 'محمد أحمد علي']), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->propose(['name_ar' => ' محمد  أحمد علي'])->assertStatus(409)->assertJsonPath('error.reason', 'name')
            ->assertJsonPath('error.match.id', $same)->assertJsonPath('error.match.here', true)->assertJsonPath('error.ambiguous', false);
        $this->assertStringNotContainsString('wage', (string) $this->propose(['name_ar' => 'محمد أحمد علي'])->getContent());

        $this->actingAs($this->hr, 'api');
        $this->employee('day_laborer', '2026-01-01', ['name_ar' => 'محمد أحمد علي']);
        $this->actingAs($this->m, 'api');
        $this->propose(['name_ar' => 'محمد أحمد علي'])->assertStatus(409)->assertJsonPath('error.match', null)->assertJsonPath('error.ambiguous', true);

        $this->propose(['name_ar' => 'محمد أحمد علي', 'confirm_new' => true])->assertCreated();
        $this->assertSame(3, DB::table('employees')->where('name_ar', 'محمد أحمد علي')->count());
    }

    public function test_a_double_submit_or_retry_returns_the_first_answer_and_adds_one_worker(): void
    {
        $this->actingAs($this->m, 'api');
        $key = (string) Str::uuid();
        $first = $this->propose(['name_ar' => 'عامل ضغطتين سريعتين', 'client_request_id' => $key])->assertCreated()->json('data');
        $again = $this->propose(['name_ar' => 'عامل ضغطتين سريعتين', 'client_request_id' => $key])->assertCreated()->json('data');
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('employees')->where('name_ar', 'عامل ضغطتين سريعتين')->count());
        $this->assertSame(1, DB::table('employee_wage_proposals')->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'wage_proposed')->count());
        // a replay that changes the wage or the phone is not the same request: refused, nothing changes (review of #72)
        $this->propose(['name_ar' => 'عامل ضغطتين سريعتين', 'client_request_id' => $key, 'daily_wage' => '٣٦٠'])->assertStatus(409)->assertJsonPath('error.code', 'client_request_reused');
        $this->propose(['name_ar' => 'عامل ضغطتين سريعتين', 'client_request_id' => $key, 'phone' => '01012345678'])->assertStatus(409);
        $this->propose(['name_ar' => 'عامل ضغطتين سريعتين', 'client_request_id' => $key, 'daily_wage' => '350.000'])->assertCreated();   // the same wage, written another way
        $this->assertSame('350.000000', (string) DB::table('employee_wage_proposals')->value('daily_wage'));

        $this->actingAs($this->other, 'api');   // another manager never replays someone else's answer
        $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s3, 'name_ar' => 'عامل ضغطتين سريعتين', 'gender' => 'male', 'daily_wage' => '300', 'client_request_id' => $key])->assertNotFound();
    }

    public function test_hr_approves_the_proposed_wage_and_the_manager_sees_the_outcome_without_money_in_the_notice(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose(['name_ar' => 'عامل أجر مقبول', 'daily_wage' => '٣٥٠٫٥٠'])->assertCreated()->json('data');
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$made['proposal_id']}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.proposed_wage', '350.500000')->assertJsonPath('data.approved_wage', null);
        $this->assertSame(0, DB::table('employee_pay_changes')->where('employee_id', $made['id'])->count());   // pending: no pay row

        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 1])->assertOk();
        $this->assertSame(['2026-10-22', '350.500000'], array_values((array) DB::table('employee_pay_changes')->where('employee_id', $made['id'])->first(['effective_from', 'daily_wage'])));

        $this->actingAs($this->m, 'api');
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$made['proposal_id']}")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_wage', '350.500000')->assertJsonPath('data.refusal_reason', null);
        $n = DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'wage_approved'])->first();
        $this->assertNotNull($n);
        $this->assertSame(['employee_id' => $made['id'], 'proposal_id' => $made['proposal_id']], json_decode($n->data, true));
    }

    public function test_hr_revises_the_wage_and_approves_it_and_the_decision_is_audited(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose(['name_ar' => 'عامل أجر معدل', 'daily_wage' => '300'])->assertCreated()->json('data');
        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 1, 'daily_wage' => '۳۲۵٫۲۵'])->assertOk();
        $this->assertSame('325.250000', DB::table('employee_pay_changes')->where('employee_id', $made['id'])->value('daily_wage'));

        $audit = DB::table('audit_log')->where(['event' => 'UPDATED', 'entity_type' => 'employee_wage_proposals', 'entity_id' => $made['proposal_id']])->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $this->hr->id, (string) $audit->user_id);
        $new = json_decode((string) $audit->new_values, true);
        $this->assertSame(['approved', '325.25'], [$new['status'], $new['approved_wage']]);   // as HR set it; stored as 325.250000
        $this->assertArrayHasKey('decided_at', $new);

        $this->actingAs($this->m, 'api');
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$made['proposal_id']}")->assertJsonPath('data.proposed_wage', '300.000000')->assertJsonPath('data.approved_wage', '325.250000');
    }

    public function test_hr_must_give_a_reason_to_refuse_and_the_manager_reads_it(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose(['name_ar' => 'عامل أجر مرفوض'])->assertCreated()->json('data');
        $this->actingAs($this->hr, 'api');
        $url = "/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse";
        $this->withHeader('Accept-Language', 'ar')->postJson($url, ['version' => 1])->assertStatus(422)->assertJsonFragment(['اكتب سبب الرفض.']);
        $this->postJson($url, ['version' => 1, 'reason' => '   '])->assertStatus(422);
        $this->postJson($url, ['version' => 1, 'reason' => ' الأجر أعلى من المعتاد '])->assertOk();

        $this->actingAs($this->m, 'api');
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$made['proposal_id']}")->assertOk()
            ->assertJsonPath('data.status', 'refused')->assertJsonPath('data.refusal_reason', 'الأجر أعلى من المعتاد')->assertJsonPath('data.approved_wage', null)
            ->assertJsonPath('data.site.name_ar', 'Site-1');   // the refusal took them off the crew; the request still names the site
        $n = DB::table('notifications')->where(['user_id' => $this->m->id, 'type' => 'wage_refused'])->value('data');
        $this->assertStringNotContainsString('الأجر', (string) $n);   // the reason stays on the request, not in the notice
    }

    public function test_a_second_or_concurrent_decision_on_the_same_version_is_a_conflict(): void
    {
        $this->actingAs($this->m, 'api');
        $made = $this->propose(['name_ar' => 'عامل قرار مزدوج'])->assertCreated()->json('data');
        $this->actingAs($this->hr, 'api');
        $base = "/api/v1/employees/wage-proposals/{$made['proposal_id']}";
        $this->postJson("$base/approve", ['version' => 1])->assertOk();
        $this->postJson("$base/approve", ['version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->postJson("$base/refuse", ['version' => 1, 'reason' => 'متأخر'])->assertStatus(409);
        $this->postJson("$base/approve", ['version' => 2])->assertStatus(409);   // decided: no second decision at any version
        $this->assertSame(1, DB::table('employee_pay_changes')->where('employee_id', $made['id'])->count());
        $this->assertSame(1, DB::table('notifications')->where(['user_id' => $this->m->id])->whereIn('type', ['wage_approved', 'wage_refused'])->count());
    }

    public function test_a_manager_reads_only_their_own_proposals(): void
    {
        $this->actingAs($this->m, 'api');
        $mine = $this->propose(['name_ar' => 'عامل المدير الأول'])->assertCreated()->json('data');
        $this->actingAs($this->other, 'api');
        $theirs = $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s3, 'name_ar' => 'عامل المدير الثاني', 'gender' => 'male', 'daily_wage' => '300'])->assertCreated()->json('data');

        $this->actingAs($this->m, 'api');
        $list = $this->getJson('/api/v1/employees/wage-proposals/mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['proposal_id'])
            ->assertJsonPath('data.0.site.name_ar', 'Site-1');
        // no figure in the list (Youssef, 2026-11-19): not in the screen and not in the response; the opened request has it
        foreach (['proposed_wage', 'approved_wage', 'refusal_reason'] as $key) {
            $list->assertJsonMissingPath("data.0.{$key}");
        }
        $this->assertStringNotContainsString('350', $list->getContent());
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$mine['proposal_id']}")->assertOk()->assertJsonPath('data.proposed_wage', '350.000000');
        $this->getJson("/api/v1/employees/wage-proposals/mine/{$theirs['proposal_id']}")->assertNotFound();
        $this->getJson('/api/v1/employees/wage-proposals')->assertForbidden();   // HR's queue stays HR's
        $this->postJson("/api/v1/employees/wage-proposals/{$mine['proposal_id']}/approve", ['version' => 1])->assertForbidden();
    }

    public function test_a_site_manager_receives_only_the_permitted_profile_fields_and_nothing_outside_their_scope(): void
    {
        $this->homeSite($in = $this->employee('permanent_worker', '2026-01-01', [
            'phone' => '01012345678', 'address' => 'عنوان تجريبي', 'department' => 'قسم تجريبي', 'national_id' => '29901013333333',
            'insurance_no' => '9876501', 'bank_account' => '000777888', 'pay_method' => 'bank', 'hr_notes' => 'ملاحظة سرية',
        ]), $this->s1, '2026-01-01');
        $this->homeSite($out = $this->employee('permanent_worker'), $this->s3, '2026-01-01');
        $office = $this->employee('office');

        $this->actingAs($this->m, 'api');
        $profile = $this->getJson("/api/v1/employees/$in")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name_ar', 'name_en', 'phone', 'address', 'department', 'residence_id', 'current_site'], array_keys($profile));
        $this->assertSame(['01012345678', 'عنوان تجريبي', 'قسم تجريبي', 'Site-1'], [$profile['phone'], $profile['address'], $profile['department'], $profile['current_site']['name_ar']]);
        $row = $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name_ar', 'name_en', 'phone', 'address', 'department', 'residence_id'], array_keys($row));
        foreach (['9000', '29901013333333', '9876501', '000777888', 'ملاحظة سرية', 'permanent_worker', 'salary', 'pay_method'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->getJson("/api/v1/employees/$in")->getContent());
            $this->assertStringNotContainsString($secret, $this->getJson('/api/v1/employees')->getContent());
        }
        // an altered id outside the manager's reach, or office staff, is a 404; a type filter tells them nothing
        $this->getJson("/api/v1/employees/$out")->assertNotFound();
        $this->getJson("/api/v1/employees/$office")->assertNotFound();
        $this->getJson('/api/v1/employees?type=engineer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/employees?search='.urlencode('٢٩٩٠١٠١٣٣٣٣٣٣٣'))->assertOk()->assertJsonCount(0, 'data');   // no national ID search

        $this->actingAs($this->hr, 'api');
        $this->getJson("/api/v1/employees/$in")->assertOk()->assertJsonPath('data.national_id', '29901013333333')->assertJsonPath('data.hr_notes', 'ملاحظة سرية');
    }

    public function test_employee_search_reads_arabic_and_persian_digit_codes(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $code = (string) DB::table('employees')->where('id', $l)->value('code');
        $arabic = strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        $persian = strtr($code, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $this->actingAs($this->m, 'api');
        $this->getJson('/api/v1/employees?search='.urlencode($arabic))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $l);
        $this->getJson('/api/v1/attendance/site-day/laborers?site_id='.$this->s1.'&search='.urlencode($persian))->assertOk()->assertJsonPath('data.match.id', $l);
    }

    public function test_numeric_fields_read_any_digits_and_free_text_stays_as_typed(): void
    {
        $request = Request::create('/api/v1/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'daily_wage' => '٣٥٠٫٥٠', 'phone' => '۰۱۰۱۲۳۴۵۶۷۸', 'national_id' => '٠٠٠١١١٢٢٢٣٣٣٤٤', 'rows' => [['in_time' => '٠٨:٣٠', 'note' => 'شارع ٥']],
            'note' => 'الدور ٣', 'name_ar' => 'محمد ٢', 'password' => '١٢٣٤', 'office_grace_minutes' => '١٥',
        ]));
        $seen = null;
        (new NormalizeDigits)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->all();

            return new Response;
        });
        $this->assertSame([
            'daily_wage' => '350.50', 'phone' => '01012345678', 'national_id' => '00011122233344', 'rows' => [['in_time' => '08:30', 'note' => 'شارع ٥']],
            'note' => 'الدور ٣', 'name_ar' => 'محمد ٢', 'password' => '١٢٣٤', 'office_grace_minutes' => '15',
        ], $seen);
    }

    public function test_malformed_numbers_are_still_refused_after_the_digits_are_read(): void
    {
        $this->actingAs($this->m, 'api');
        foreach (['٣٥٠٫٥٫٠', '٣٥٠,٥٠', '-٣٠٠', '٠', 'abc'] as $bad) {
            $this->propose(['name_ar' => 'عامل أجر خطأ', 'daily_wage' => $bad])->assertStatus(422);
        }
        $this->propose(['name_ar' => 'عامل هاتف خطأ', 'phone' => '٠١٣١٢٣٤٥٦٧٨'])->assertStatus(422);
        $this->assertSame(0, DB::table('employees')->where('name_ar', 'like', 'عامل % خطأ')->count());
    }

    /** @param  array<string, mixed>  $over */
    public function test_a_proposal_hrs_workbook_import_made_tells_no_one_that_their_wage_was_decided(): void
    {
        // HR's site-workbook import proposes inside HR's own request, so created_by is the HR user (review of #72)
        $this->actingAs($this->hr, 'api');
        $made = app(DayCrew::class)->propose(['name_ar' => 'عامل من الإكسيل', 'gender' => 'male', 'phone' => null], $this->s1, '2026-10-22', '300');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/approve", ['version' => 1])->assertOk();
        $this->assertSame(0, DB::table('notifications')->whereIn('type', ['wage_approved', 'wage_refused'])->count());

        $this->actingAs($this->m, 'api');   // a site manager's own proposal still hears back
        $mine = $this->propose(['name_ar' => 'عامل المدير نفسه'])->assertCreated()->json('data');
        $this->actingAs($this->hr, 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$mine['proposal_id']}/refuse", ['version' => 1, 'reason' => 'سبب'])->assertOk();
        $this->assertSame(1, DB::table('notifications')->where(['type' => 'wage_refused', 'user_id' => $this->m->id])->count());
    }

    public function test_a_site_manager_reads_the_employee_total_only(): void
    {
        $this->homeSite($this->employee('permanent_worker'), $this->s1, '2026-01-01');
        $this->actingAs($this->m, 'api');
        $this->assertSame(['total' => 1], $this->getJson('/api/v1/employees/summary')->assertOk()->json('data'));
    }

    public function test_every_settings_rule_and_the_range_and_search_keys_read_any_digits(): void
    {
        $key = (new \ReflectionClassConstant(NormalizeDigits::class, 'NUMERIC_KEY'))->getValue();
        // every numeric settings rule (the weekly days off are weekday names, not numbers)
        $numeric = array_keys(array_filter(SettingController::RULES, fn (string $rule) => $rule !== (new \ReflectionClassConstant(SettingController::class, 'DAYS'))->getValue()));
        foreach ([...$numeric, 'from', 'to', 'q', 'returns_on', 'expires_at'] as $name) {
            $this->assertSame(1, preg_match($key, $name), "{$name} is not normalized");
        }
        foreach (['name_ar', 'note', 'reason', 'password', 'email'] as $name) {
            $this->assertSame(0, preg_match($key, $name), "{$name} must stay as typed");
        }
    }

    private function propose(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد أول', 'gender' => 'male', 'daily_wage' => '350', ...$over]);
    }
}
