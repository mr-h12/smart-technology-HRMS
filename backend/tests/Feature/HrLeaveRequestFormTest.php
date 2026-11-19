<?php

namespace Tests\Feature;

use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Application\Types\LeaveRequestPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrLeaveRequestFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const FIELDS = ['job_title' => 'Synthetic QA', 'notification_method' => 'Phone', 'manager_opinion' => 'Test opinion', 'substitute' => 'Test substitute'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        Queue::fake();
        Storage::fake('local');
    }

    /** The kinds row's label of each kind: the marked circle stands right after it. */
    private const KIND_LABELS = ['annual' => 'نوع الإجازة (اعتيادية', 'casual' => '- عارضة', 'sick' => '-مرضي', 'unpaid' => '- بدون أجر'];

    public static function kinds(): array
    {
        return [
            ['annual', 'female'], ['annual', 'male'], ['casual', 'female'], ['casual', 'male'],
            ['sick', 'female'], ['sick', 'male'], ['unpaid', 'female'], ['unpaid', 'male'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_mapping_scope_and_render(string $kind, string $gender): void
    {
        $hr = $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01', ['gender' => $gender, 'department' => 'Test department']);
        $request = $this->submit($employee, $kind);
        $this->signIn('manager');
        $this->create($request)->assertForbidden();
        $this->actingAs($hr, 'api');
        $key = (string) Str::uuid7();
        $id = $this->generate($request, $key);
        $this->assertSame($id, $this->generate($request, $key));
        $snapshot = $this->snapshot($id);
        $expected = [...self::FIELDS, 'kind' => $kind, 'gender' => $gender, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05',
            'days' => 1, 'reason' => 'Test leave reason', 'department' => 'Test department',
            'balance' => app(LeaveBalances::class)->forEmployee($employee, 2026)['balance']];
        $this->assertEquals($expected, array_intersect_key($snapshot, $expected));
        $this->render($id, fn (string $html): bool => ! str_contains($html, '2026-11-05') && ! str_contains($html, '<img src='));
        $this->postJson("/api/v1/hr-form-exports/{$id}/approve")->assertConflict();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
        $this->signIn('manager');
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertNotFound();
        $this->actingAs($hr, 'api');
        $second = $this->generate($request);
        $this->assertDatabaseHas('hr_form_exports', ['id' => $second, 'serial_number' => 2, 'regenerated_from_id' => $id]);
    }

    public function test_signed_pdf_survives_cancellation_and_retry_does_not_duplicate(): void
    {
        $hr = $this->signIn('hr');
        $request = $this->submit($this->employee('office', '2020-01-01'), 'annual');
        $this->decision($request, 'approve')->assertOk();
        $older = $this->generate($request);
        $this->render($older);
        $unsigned = $this->generate($request);
        $this->render($unsigned);
        $approve = "/api/v1/hr-form-exports/{$unsigned}/approve";
        $this->postJson("/api/v1/hr-form-exports/{$older}/approve")->assertConflict();
        $this->signIn('hr');
        $this->postJson($approve)->assertForbidden();
        $this->actingAs($hr, 'api');
        $signature = $this->signature($hr->id);
        $signed = $this->postJson($approve)->assertAccepted()->assertJsonPath('data.serial_number', 2)->json('data.id');
        $this->assertSame('hr', $this->snapshot($signed)['stamp_line']);
        $this->assertSame($hr->id, $this->snapshot($signed)['decider_id']);
        $this->assertSame(1, $this->snapshot($signed)['signature_version']);
        (new RenderPdf($signed))->failed(null);
        $this->postJson("/api/v1/hr-form-exports/{$signed}/retry")->assertAccepted()->assertJsonPath('data.id', $signed);
        $this->assertDatabaseHas('hr_form_exports', ['id' => $signed, 'signature_image_id' => $signature]);
        $this->render($signed, fn (string $html): bool => (bool) preg_match('/data-stamp-line="hr">\s*<div>[^<]+<\/div>\s*<img/', $html));
        $this->decision($request, 'cancel')->assertOk();
        $this->assertSame('%PDF-1.7 leave', $this->get("/api/v1/hr-form-exports/{$signed}/download")->assertOk()->streamedContent());
        $after = $this->generate($request);
        $this->render($after);
        $this->postJson("/api/v1/hr-form-exports/{$after}/approve")->assertConflict();
        $this->assertSame(1, DB::table('audit_log')->where(['entity_id' => $signed, 'event' => 'APPROVED'])->count());
        $this->assertSame(1, DB::table('hr_form_exports')->where('stage', 'signed')->count());
    }

    public function test_escalated_approval_is_refused_in_view_as_and_migration_backfill(): void
    {
        $this->leaveTypes();
        $hr = $this->signIn('hr');
        $request = $this->submit($this->employee('office', '2020-01-01'), 'sick');
        $other = $this->submit($this->employee('office', '2020-01-01'), 'unpaid');
        $this->decision($other, 'refuse', ['reason' => 'Synthetic refusal'])->assertOk();
        $refused = $this->generate($other);
        $this->render($refused);
        $this->postJson("/api/v1/hr-form-exports/{$refused}/approve")->assertConflict();
        $this->decision($request, 'escalate')->assertOk();
        $this->assertDatabaseHas('leave_requests', ['id' => $request, 'escalated_by' => $hr->id]);
        $unsigned = $this->generate($request);
        $this->render($unsigned);
        $this->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertConflict();
        $ceo = $this->signIn('ceo');
        $this->decision($request, 'approve')->assertOk();

        $migration = require database_path('migrations/2026_11_12_000002_record_leave_escalations.php');
        $migration->down();
        Log::spy();
        $migration->up();
        Log::shouldHaveReceived('info')->with('leave_escalation_backfill', ['backfilled' => 1, 'without_escalation_audit' => 1])->once();
        $this->assertDatabaseHas('leave_requests', ['id' => $request, 'escalated_by' => $hr->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $other, 'escalated_at' => null, 'escalated_by' => null]);

        $this->signature($ceo->id);
        $developer = $this->signIn('developer');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $developer->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $view = $this->withToken($token)->postJson('/api/v1/auth/view-as', ['user_id' => $ceo->id, 'reason' => 'Synthetic QA'])->assertCreated()->json('data.token');
        // the developer viewing as the CEO must not stamp the CEO's stored signature
        $this->withToken($view)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertForbidden();
        $this->assertDatabaseHas('hr_form_exports', ['id' => $unsigned, 'approved_by' => null, 'signature_image_id' => null]);
        app('auth')->forgetGuards();
        $own = $this->postJson('/api/v1/auth/login', ['email' => $ceo->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $signed = $this->withToken($own)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")
            ->assertAccepted()->json('data.id');
        $this->assertSame('company_manager', $this->snapshot($signed)['stamp_line']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $signed, 'event' => 'APPROVED', 'user_id' => $ceo->id, 'on_behalf_of' => null]);
        $this->render($signed, fn (string $html): bool => (bool) preg_match('/data-stamp-line="company_manager">\s*<div>[^<]+<\/div>\s*<img/', $html));
    }

    #[DataProvider('kinds')]
    public function test_each_kind_and_gender_marks_its_circle_and_prints_its_wording(string $kind, string $gender): void
    {
        $this->signIn('hr');
        $html = $this->html($this->generate($this->submit($this->employee('office', '2020-01-01', ['gender' => $gender]), $kind)));

        $this->assertSame(1, substr_count($html, 'circle marked'));
        $this->assertStringContainsString(self::KIND_LABELS[$kind].' <span class="circle marked"', $html);
        foreach (array_diff_key(self::KIND_LABELS, [$kind => true, 'unpaid' => true]) as $label) {
            $this->assertStringContainsString($label.' <span class="circle"', $html);
        }
        // Table ١-ب: the applicant's two phrases follow the gender; the substitute's line stays masculine.
        $female = $gender === 'female';
        $this->assertStringContainsString($female ? 'مقدمة لسيادتكم / ' : 'مقدم لسيادتكم / ', $html);
        $this->assertStringContainsString($female ? 'توقيع مقدمة الطلب/' : 'توقيع مقدم الطلب/', $html);
        $this->assertStringNotContainsString($female ? 'مقدم لسيادتكم' : 'مقدمة', $html);
        // Table ١-أ: the corrected spellings, and none of the company form's misspellings.
        foreach (['تحية طيبة وبعد،،،', 'برجاء من سيادتكم الموافقة على منحي إجازة لمدة', 'إلى يوم', 'وتفضلوا سيادتكم بقبول فائق الاحترام،،،',
            'الاستعلام عن رصيد الإجازات', 'رأي مدير القسم', 'توقيع الموظف الذي ينوب العمل', 'اعتيادية'] as $phrase) {
            $this->assertStringContainsString($phrase, $html);
        }
        foreach (['طيبه', 'سياتكم', 'أعتيادية', 'منحى', 'أجازة', 'الى ', 'الأحترام', 'الاجازات', 'رأى', 'الذى'] as $misspelt) {
            $this->assertStringNotContainsString($misspelt, $html);
        }
    }

    public function test_only_an_unpaid_leave_prints_bidun_ajr(): void
    {
        $this->signIn('hr');
        foreach (['annual' => 0, 'casual' => 0, 'sick' => 0, 'unpaid' => 1] as $kind => $count) {
            $html = $this->html($this->generate($this->submit($this->employee('office', '2020-01-01'), $kind)));
            $this->assertSame($count, substr_count($html, 'بدون أجر'), $kind);
        }
    }

    public function test_two_days_print_yawmayn_and_each_date_prints_twice(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01');
        $two = $this->postJson('/api/v1/leave/requests', ['employee_id' => $employee, 'kind' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-08'])->assertCreated()->assertJsonPath('data.days', 2)->json('data.id');
        $html = $this->html($this->generate($two));
        $this->assertStringContainsString('لمدة (يومين) ابتداء من يوم الخميس الموافق <span class="date">05 / 11 / 2026م</span> إلى يوم الأحد الموافق <span class="date">08 / 11 / 2026م</span> وذلك لظروف', $html);

        $one = $this->html($this->generate($this->submit($this->employee('office', '2020-01-01'), 'casual')));
        $this->assertStringContainsString('لمدة (يوم واحد)', $one);
        $this->assertSame(2, substr_count($one, 'الخميس الموافق <span class="date">05 / 11 / 2026م</span>'));
    }

    public function test_each_typed_value_is_in_a_bdi_and_an_empty_department_and_method_print_dots(): void
    {
        $this->signIn('hr');
        $filled = $this->html($this->generate($this->submit($this->employee('office', '2020-01-01', ['department' => 'Test department']), 'annual')));
        foreach (['Synthetic QA', 'Phone', 'Test opinion', 'Test substitute', 'Test leave reason', 'Test department'] as $value) {
            $this->assertStringContainsString("<bdi>{$value}</bdi>", $filled);
        }
        $this->assertMatchesRegularExpression('#<bdi>[^<]+</bdi></p>#u', $filled, 'the name line');

        $request = $this->submit($this->employee('office', '2020-01-01', ['department' => null]), 'annual');
        $empty = $this->html($this->generate($request, fields: ['job_title' => 'Synthetic QA']));
        foreach (['القسم/', 'طريقة التبليغ/'] as $label) {
            $this->assertMatchesRegularExpression('#'.$label.'</span><span class="[^"]*\bdl\b[^"]*"[^>]*>\.{25}</span></span></p>#u', $empty);
        }
        $this->assertSame(6, substr_count($empty, '<bdi>'), 'name, job title, reason, balance days and year, serial');
    }

    public function test_the_balance_cell_prints_unavailable_and_zero_days(): void
    {
        $this->signIn('hr');
        $id = $this->generate($this->submit($this->employee('office', '2020-01-01'), 'annual'));
        $this->assertStringContainsString('<td> الرصيد قبل هذه الإجازة: <bdi>21 يومًا</bdi> (سنة <bdi>2026</bdi>)</td>', $this->html($id));

        foreach ([[0, '<td> الرصيد قبل هذه الإجازة: <bdi>0 يوم</bdi> (سنة <bdi>2026</bdi>)</td>'], [null, '<td>غير متاح</td>']] as [$remaining, $cell]) {
            $snapshot = $this->snapshot($id);
            $snapshot['balance']['remaining'] = $remaining;
            DB::table('hr_form_exports')->where('id', $id)->update(['snapshot' => json_encode($snapshot), 'status' => 'pending', 'sha256' => null, 'size_bytes' => null]);
            Storage::disk('local')->delete("hr-forms/{$id}.pdf");
            $this->assertStringContainsString($cell, $this->html($id));
        }
    }

    public function test_the_signature_prints_only_on_the_stamp_line(): void
    {
        $hr = $this->signIn('hr');
        $request = $this->submit($this->employee('office', '2020-01-01'), 'annual');
        $this->decision($request, 'approve')->assertOk();
        $unsigned = $this->generate($request);
        $this->render($unsigned);
        $this->signature($hr->id);
        $signed = $this->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertAccepted()->json('data.id');
        $html = $this->html($signed);

        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<div data-stamp-line="hr"><div>توقيع الموارد البشرية</div><img src="data:image/png;base64,[^"]+" alt="توقيع الموارد البشرية"></div>#u', $html);
        $this->assertMatchesRegularExpression('#<div data-stamp-line="company_manager"><div>توقيع مدير الشركة</div><div class="dots">\.{23}</div></div>#u', $html);
    }

    /** @return array<string, array{0: array<string, int>, 1: int}> value lengths over the fixture's, and the size the copy prints at */
    public static function sixteenBounds(): array
    {
        return [
            'reason 25 beside one spilt value' => [['reason' => 25, 'name_ar' => 48], 16],
            'reason 26 beside one spilt value' => [['reason' => 26, 'name_ar' => 48], 11],
            'reason 100' => [['reason' => 100], 16],
            'reason 101' => [['reason' => 101], 13],
            'name at its one line' => [['reason' => 26, 'name_ar' => 47], 16],
            'name past its one line' => [['reason' => 26, 'name_ar' => 48], 11],
            'department at its one line' => [['reason' => 26, 'department' => 62], 16],
            'department past its one line' => [['reason' => 26, 'department' => 63], 9],
            'substitute at its one line' => [['reason' => 26, 'substitute' => 28], 16],
            'substitute past its one line' => [['reason' => 26, 'substitute' => 29], 11],
            'opinion at its one line' => [['reason' => 26, 'manager_opinion' => 38], 16],
            'opinion past its one line' => [['reason' => 26, 'manager_opinion' => 39], 13],
            'job title and method never spill' => [['reason' => 26, 'job_title' => 60, 'notification_method' => 60], 16],
        ];
    }

    /** @param array<string, int> $lengths */
    #[DataProvider('sixteenBounds')]
    public function test_a_copy_prints_at_16_pt_at_each_bound(array $lengths, int $size): void
    {
        $snapshot = ['name_ar' => 'Synthetic', 'department' => 'QA', 'job_title' => 'QA', 'notification_method' => '', 'manager_opinion' => '', 'substitute' => '',
            'reason' => '', 'days' => 1, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'balance' => ['remaining' => 21], 'balance_year' => 2026];
        foreach ($lengths as $key => $length) {
            $snapshot[$key] = str_repeat('ب', $length);
        }

        $this->assertSame($size, app(LeaveRequestPdf::class)->view($snapshot)[1]['valueSize']);
    }

    public function test_a_reason_of_zero_prints_zero(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01');
        $request = $this->postJson('/api/v1/leave/requests', ['employee_id' => $employee, 'kind' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'note' => '0'])->assertCreated()->json('data.id');
        $html = $this->html($this->generate($request));

        $this->assertStringContainsString('وذلك لظروف</span> <bdi>0</bdi></p>', $html);
    }

    public function test_the_form_fields_have_printable_limits(): void
    {
        $this->signIn('hr');
        $request = $this->submit($this->employee('office', '2020-01-01'), 'annual');
        foreach (['job_title' => 60, 'notification_method' => 60, 'manager_opinion' => 130, 'substitute' => 60] as $field => $limit) {
            $this->create($request, fields: [...self::FIELDS, $field => str_repeat('ب', $limit + 1)])->assertUnprocessable()
                ->assertJsonPath('error.details.0.field', "fields.{$field}");
            $this->create($request, fields: [...self::FIELDS, $field => str_repeat('ب', $limit)])->assertAccepted();
        }
    }

    public static function recordValues(): array
    {
        return [
            ['name_ar', 'employees', 90, [
                'ar' => 'قيمة «الاسم بالعربية» أطول مما يتسع له نموذج طلب الإجازة (90 حرفًا على الأكثر). اختصرها في السجل ثم أنشئ النموذج.',
                'en' => 'Arabic name is longer than the leave form can print (90 characters at most). Shorten it in the record, then generate the form.',
            ]],
            ['department', 'employees', 90, [
                'ar' => 'قيمة «القسم» أطول مما يتسع له نموذج طلب الإجازة (90 حرفًا على الأكثر). اختصرها في السجل ثم أنشئ النموذج.',
                'en' => 'Department is longer than the leave form can print (90 characters at most). Shorten it in the record, then generate the form.',
            ]],
            ['note', 'leave_requests', 360, [
                'ar' => 'ملاحظة الطلب أطول مما يتسع له نموذج طلب الإجازة (360 حرفًا على الأكثر). لا يمكن تعديل ملاحظة طلب بعد تقديمه، لذلك لا يمكن إنشاء نموذج هذا الطلب.',
                'en' => "The request's note is longer than the leave form can print (360 characters at most). A filed request's note cannot be changed, so this request's form cannot be generated.",
            ]],
        ];
    }

    /** @param  array<string, string>  $messages  by locale */
    #[DataProvider('recordValues')]
    public function test_a_name_or_department_over_90_or_a_stored_note_over_360_refuses_generation_with_no_row(string $column, string $table, int $limit, array $messages): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01');
        $request = $this->submit($employee, 'annual');
        $row = $table === 'employees' ? $employee : $request;
        DB::table($table)->where('id', $row)->update([$column => str_repeat('ب', $limit)]);
        $this->generate($request);
        DB::table($table)->where('id', $row)->update([$column => str_repeat('ب', $limit + 1)]);

        foreach ($messages as $locale => $message) {
            $this->withHeader('Accept-Language', $locale)->create($request)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.details.0.field', $column)->assertJsonPath('error.details.0.message', $message);
        }
        $this->assertSame(1, DB::table('hr_form_exports')->count());
        $this->assertSame(1, (int) DB::table('hr_form_serial_counters')->where('form_type', 'leave_request')->value('last_serial'));
    }

    public function test_the_balance_is_the_one_before_this_leave(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01');
        $request = $this->postJson('/api/v1/leave/requests', ['employee_id' => $employee, 'kind' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-09'])->assertCreated()->assertJsonPath('data.days', 3)->json('data.id');
        $pending = $this->generate($request);
        $this->decision($request, 'approve')->assertOk();
        $approved = $this->generate($request);

        $this->assertSame([21, 21], [$this->snapshot($pending)['balance']['remaining'], $this->snapshot($approved)['balance']['remaining']]);
    }

    private function submit(string $employee, string $kind): string
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $employee, 'kind' => $kind,
            'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'note' => 'Test leave reason'])->assertCreated()->json('data.id');
    }

    private function decision(string $id, string $action, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/leave/requests/{$id}/{$action}", [
            ...$extra, 'version' => (int) DB::table('leave_requests')->where('id', $id)->value('version'),
        ]);
    }

    private function create(string $request, ?string $key = null, array $fields = self::FIELDS): TestResponse
    {
        return $this->postJson('/api/v1/hr-form-exports', [
            'type' => 'leave_request', 'subject_id' => $request, 'idempotency_key' => $key ?? (string) Str::uuid7(), 'fields' => $fields,
        ]);
    }

    private function generate(string $request, ?string $key = null, array $fields = self::FIELDS): string
    {
        return $this->create($request, $key, $fields)->assertAccepted()->json('data.id');
    }

    private function html(string $id): string
    {
        $html = '';
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->willReturnCallback(function (string $page) use (&$html): string {
            $html = $page;

            return '%PDF-1.7 leave';
        });
        (new RenderPdf($id))->handle($renderer);

        return $html;
    }

    private function snapshot(string $id): array
    {
        return json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function render(string $id, ?callable $assertHtml = null): void
    {
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback($assertHtml ?? fn (string $html) => str_contains($html, 'نموذج طلب إجازة')))->willReturn('%PDF-1.7 leave');
        (new RenderPdf($id))->handle($renderer);
    }

    private function signature(string $actor): string
    {
        $id = (string) Str::uuid7();
        $path = "hr-signatures/{$id}.png";
        Storage::disk('local')->put($path, 'synthetic stamp bytes');
        DB::table('hr_signature_images')->insert([
            'id' => $id, 'owner_id' => $actor, 'version' => 1, 'storage_path' => $path, 'mime_type' => 'image/png',
            'checksum' => hash('sha256', 'synthetic stamp bytes'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
