<?php

namespace Tests\Feature;

use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Application\Types\ExperienceCertificatePdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Infrastructure\FilePdfAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;
use Throwable;

class HrExperienceCertificateTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const EMPLOYEE = ['name_ar' => 'Synthetic name', 'national_id' => '29901011234567', 'department' => 'QA'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-18 12:00');
        Queue::fake();
        Storage::fake('local');
    }

    /** Tables ٢-أ and ٢-ب, issued on Wednesday 18 November 2026 to an employee hired on 1 January 2022. */
    public static function genders(): array
    {
        return [
            'female' => ['female', [
                'هذه الشهادة مقدمة من شركة/ سمارت تكنولوجي للأنظمة المتكاملة بأن الموظفة المذكورة بياناتها أدناه',
                'اسم الموظفة/ Synthetic name',
                'بأنها انضمت للعمل بوظيفة Synthetic QA ضمن فريق العمل في الشركة لدى فترة زمنية تجاوزت أربع سنوات وعشرة أشهر وهي من أكفاء الموظفين الذين قد مروا على الشركة حيث كانت متميزة في أداء جميع مهامها الوظيفية وكانت تتمتع بالتقدير والاحترام والثقة الكاملة من جميع المسئولين لدى الشركة.',
                'تم تحرير هذه الشهادة في يوم الأربعاء الموافق 18 / 11 / 2026 بناء على رغبة الموظفة دون أدنى مسئولية من الشركة.',
            ], ['اسم الموظف/', 'بأنه انضم', 'وهو من', 'كان متميز', 'مهامه الوظيفية', 'وكان يتمتع', 'رغبة الموظف دون']],
            'male' => ['male', [
                'هذه الشهادة مقدمة من شركة/ سمارت تكنولوجي للأنظمة المتكاملة بأن الموظف المذكورة بياناته أدناه',
                'اسم الموظف/ Synthetic name',
                'بأنه انضم للعمل بوظيفة Synthetic QA ضمن فريق العمل في الشركة لدى فترة زمنية تجاوزت أربع سنوات وعشرة أشهر وهو من أكفاء الموظفين الذين قد مروا على الشركة حيث كان متميزاً في أداء جميع مهامه الوظيفية وكان يتمتع بالتقدير والاحترام والثقة الكاملة من جميع المسئولين لدى الشركة.',
                'تم تحرير هذه الشهادة في يوم الأربعاء الموافق 18 / 11 / 2026 بناء على رغبة الموظف دون أدنى مسئولية من الشركة.',
            ], ['الموظفة', 'بياناتها', 'بأنها', 'انضمت', 'وهي من', 'كانت', 'مهامها']],
        ];
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $absent  the other gender's words
     */
    #[DataProvider('genders')]
    public function test_both_genders_print_the_companys_wording_under_a_fixed_clock(string $gender, array $present, array $absent): void
    {
        $this->signIn('hr');
        $text = $this->text($this->html($this->generate($this->employee('office', '2022-01-01', [...self::EMPLOYEE, 'gender' => $gender]))));

        foreach (['شهـــادة خبرة', 'اليوم:الأربعاء التاريخ: 18 / 11 / 2026', 'المسمى الوظيفي/ Synthetic QA', 'بطاقة رقم قومي/ 29901011234567', 'قسم/ QA', 'مدير الشركة', ...$present] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
        // Table ٢-أ's misspellings, trunk's rewording, and an «م» after the date.
        foreach (['أسم', 'المسمي', 'الوظيفى', 'فى ', 'مرو ', 'الوظيفيه', 'الأحترام', 'لمدة', 'المسؤولين', 'بناءً', '2026م', ...$absent] as $phrase) {
            $this->assertStringNotContainsString($phrase, $text);
        }
    }

    public function test_a_termination_date_ends_the_duration(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', [...self::EMPLOYEE, 'termination_date' => '2026-12-31']);

        $this->assertStringContainsString('لدى فترة زمنية تجاوزت أربع سنوات وأحد عشر شهرًا وهو من', $this->text($this->html($this->generate($employee))));
    }

    public function test_an_archived_employee_gets_a_certificate(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', [...self::EMPLOYEE, 'gender' => 'female', 'termination_date' => '2026-10-18']);
        $this->getJson("/api/v1/employees/{$employee}")->assertOk()->assertJsonPath('data.archived', true);

        $text = $this->text($this->html($this->generate($employee)));
        $this->assertStringContainsString('لدى فترة زمنية تجاوزت أربع سنوات وتسعة أشهر وهي من', $text);
        $this->assertStringContainsString('بناء على رغبة الموظفة دون أدنى مسئولية من الشركة.', $text);
    }

    public function test_the_signature_prints_only_on_the_signed_copy(): void
    {
        $this->signIn('hr');
        $unsigned = $this->generate($this->employee('office', '2022-01-01', self::EMPLOYEE));
        $html = $this->html($unsigned);
        $this->assertSame(3, substr_count($html, '<img'), 'the letterhead, the logo and the footer');
        $this->assertMatchesRegularExpression('#<div class="sign"><div>مدير الشركة</div></div>#u', $html);

        $ceo = $this->signIn('ceo');
        $this->signature($ceo->id);
        $signed = $this->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertAccepted()->json('data.id');
        $html = $this->html($signed);
        $this->assertSame(4, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('#<div class="sign"><div>مدير الشركة</div><img src="data:image/png;base64,[^"]+" alt="مدير الشركة"></div>#u', $html);
    }

    public function test_each_value_prints_in_a_bdi(): void
    {
        $this->signIn('hr');
        $html = $this->html($this->generate($this->employee('office', '2022-01-01', [...self::EMPLOYEE, 'name_ar' => 'Synthetic <b>name</b>'])));

        foreach (['Synthetic &lt;b&gt;name&lt;/b&gt;' => 1, 'Synthetic QA' => 2, '29901011234567' => 1, 'QA' => 1] as $value => $times) {
            $this->assertSame($times, substr_count($html, "<bdi>{$value}</bdi>"), $value);
        }
        $this->assertStringNotContainsString('<b>name</b>', $html);
    }

    /** @return array<string, array{0: array<string, int>, 1: int, 2: float}> value lengths, the value size and the line spacing */
    public static function steps(): array
    {
        return [
            'the fixture' => [[], 15, 2.0],
            'a name at a quarter of 255' => [['name_ar' => 63], 15, 2.0],
            'a name just past a quarter' => [['name_ar' => 64], 13, 1.75],
            'a job title at 0.6 of 160' => [['job_title' => 96], 13, 1.75],
            'a job title just past 0.6' => [['job_title' => 97], 11, 1.5],
            'a department at 0.6 of 255' => [['department' => 153], 13, 1.75],
            'a department just past 0.6' => [['department' => 154], 11, 1.5],
            'every value at its limit' => [['name_ar' => 255, 'department' => 255, 'job_title' => 160], 11, 1.5],
        ];
    }

    /** @param array<string, int> $lengths */
    #[DataProvider('steps')]
    public function test_each_step_sets_the_value_size_and_line_spacing(array $lengths, int $size, float $spacing): void
    {
        $snapshot = ['name_ar' => 'Synthetic', 'national_id' => '29901011234567', 'department' => 'QA', 'gender' => 'male', 'hire_date' => '2022-01-01',
            'termination_date' => null, 'job_title' => 'QA', 'issued_on' => '2026-11-18'];
        foreach ($lengths as $key => $length) {
            $snapshot[$key] = str_repeat('ب', $length);
        }
        $data = app(ExperienceCertificatePdf::class)->view($snapshot)[1];

        $this->assertSame([$size, $spacing], [$data['valueSize'] ?? null, $data['lineSpacing'] ?? null]);
    }

    public function test_a_stored_praise_is_not_printed(): void
    {
        $this->signIn('hr');
        $id = $this->generate($this->employee('office', '2022-01-01', self::EMPLOYEE));
        $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true);
        $this->assertArrayNotHasKey('praise', $snapshot);
        // A snapshot made while HR could still edit the praise.
        DB::table('hr_form_exports')->where('id', $id)->update(['snapshot' => json_encode([...$snapshot, 'praise' => 'إشادة للاختبار'])]);

        $this->assertStringNotContainsString('إشادة للاختبار', $this->html($id));
        $this->assertArrayNotHasKey('praise', app(ExperienceCertificatePdf::class)->view([...$snapshot, 'praise' => 'إشادة للاختبار'])[1]);
    }

    public function test_worker_renders_once_and_publishes_only_ready_files(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', self::EMPLOYEE);
        $id = $this->generate($employee);
        $this->getJson("/api/v1/hr-form-exports/{$id}/download")->assertNotFound();
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, '<bdi>Synthetic QA</bdi>')))
            ->willReturn('%PDF-1.7 synthetic');
        $job = new RenderPdf($id);
        $job->handle($renderer);
        $job->handle($renderer);
        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.sha256', hash('sha256', '%PDF-1.7 synthetic'))
            ->assertJsonPath('data.size_bytes', strlen('%PDF-1.7 synthetic'));
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
        $this->getJson("/api/v1/hr-form-exports?employee_id={$employee}")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/hr-form-exports?awaiting_approval=1')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn('manager');
        $this->getJson("/api/v1/hr-form-exports/{$id}/download")->assertNotFound();
        $this->signIn('ceo');
        $this->getJson('/api/v1/hr-form-exports?awaiting_approval=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertSame(1, DB::table('audit_log')->where('event', 'GENERATED')->where('entity_id', $id)->count());
    }

    public function test_rows_of_postponed_form_types_are_no_longer_served(): void
    {
        $hr = $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01');
        $id = (string) Str::uuid7();
        DB::table('hr_form_exports')->insert([
            'id' => $id, 'form_type' => 'end_of_service', 'serial_number' => 1, 'stage' => 'unsigned', 'status' => 'ready',
            'employee_id' => $employee, 'requested_by' => $hr->id, 'idempotency_key' => (string) Str::uuid7(),
            'template_version' => 1, 'snapshot' => json_encode(['final_amount' => '6172.835000']),
            'storage_path' => "hr-forms/{$id}.pdf", 'sha256' => str_repeat('a', 64), 'size_bytes' => 8,
            'created_by' => $hr->id, 'updated_by' => $hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->put("hr-forms/{$id}.pdf", '%PDF-1.7');

        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertNotFound();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertNotFound();
        $this->getJson("/api/v1/hr-form-exports?employee_id={$employee}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_certificate_prints_the_company_logo_and_a_missing_asset_fails_loudly(): void
    {
        $this->signIn('hr');
        $html = $this->html($this->generate($this->employee('office', '2022-01-01', self::EMPLOYEE)));
        $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('pdf/letterhead/logo.png')));
        $this->assertStringContainsString($logo, $html);

        $this->expectException(Throwable::class);
        FilePdfAssets::asset('letterhead/missing.png', 'image/png');
    }

    public function test_view_as_cannot_approve_and_approval_retains_the_serial_and_retries_the_same_export(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', self::EMPLOYEE);
        $key = (string) Str::uuid7();
        $older = $this->create($employee, $key)->assertAccepted()->json('data.id');
        $this->create($employee, $key)->assertAccepted()->assertJsonPath('data.id', $older);
        $unsigned = $this->create($employee)->assertAccepted()
            ->assertJsonPath('data.serial_number', 2)->assertJsonPath('data.regenerated_from_id', $older)->json('data.id');
        DB::table('hr_form_exports')->whereIn('id', [$older, $unsigned])->update([
            'status' => 'ready', 'sha256' => str_repeat('a', 64), 'size_bytes' => 123,
        ]);

        $ceo = $this->makeUser('ceo');
        $signature = $this->signature($ceo->id);
        $developer = $this->signIn('developer');
        $this->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertForbidden();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $developer->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $view = $this->withToken($token)->postJson('/api/v1/auth/view-as', ['user_id' => $ceo->id, 'reason' => 'Synthetic QA'])
            ->assertCreated()->json('data.token');
        // the developer viewing as the CEO must not stamp the CEO's stored signature
        $this->withToken($view)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")->assertForbidden();
        $this->assertDatabaseHas('hr_form_exports', ['id' => $unsigned, 'approved_by' => null, 'signature_image_id' => null]);
        app('auth')->forgetGuards();
        $own = $this->postJson('/api/v1/auth/login', ['email' => $ceo->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $this->withToken($own)->postJson("/api/v1/hr-form-exports/{$older}/approve")->assertConflict();
        $signed = $this->withToken($own)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve")
            ->assertAccepted()->assertJsonPath('data.serial_number', 2)->json('data.id');
        $audit = ['event' => 'APPROVED', 'entity_type' => 'hr_form_exports', 'entity_id' => $signed];
        $this->assertDatabaseHas('audit_log', [...$audit, 'user_id' => $ceo->id, 'on_behalf_of' => null]);
        (new RenderPdf($signed))->failed(null);
        $this->withToken($own)->getJson("/api/v1/hr-form-exports/{$signed}")->assertOk()
            ->assertJsonPath('data.failure_code', 'render_failed');
        $this->withToken($own)->postJson("/api/v1/hr-form-exports/{$signed}/retry")
            ->assertAccepted()->assertJsonPath('data.id', $signed)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('hr_form_exports', [
            'id' => $signed, 'serial_number' => 2, 'signature_image_id' => $signature,
            'storage_path' => "hr-forms/{$signed}.pdf", 'approved_by' => $ceo->id,
        ]);
        $this->assertDatabaseHas('hr_form_exports', ['id' => $unsigned, 'status' => 'ready']);
        $this->assertSame(1, DB::table('audit_log')->where($audit)->count());
        Queue::assertPushedOn('pdf', RenderPdf::class, fn (RenderPdf $job) => $job->exportId === $signed);
    }

    private function create(string $employee, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/hr-form-exports', [
            'type' => 'experience_certificate', 'subject_id' => $employee, 'idempotency_key' => $key ?? (string) Str::uuid7(),
            'fields' => ['job_title' => 'Synthetic QA'],
        ]);
    }

    private function generate(string $employee): string
    {
        return $this->create($employee)->assertAccepted()->json('data.id');
    }

    private function html(string $id): string
    {
        $html = '';
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->willReturnCallback(function (string $page) use (&$html): string {
            $html = $page;

            return '%PDF-1.7 certificate';
        });
        (new RenderPdf($id))->handle($renderer);

        return $html;
    }

    private function text(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(substr($html, (int) strpos($html, '<body>')))));
    }

    private function signature(string $owner): string
    {
        $id = (string) Str::uuid7();
        $path = "hr-signatures/{$id}.png";
        Storage::disk('local')->put($path, 'synthetic stamp bytes');
        DB::table('hr_signature_images')->insert([
            'id' => $id, 'owner_id' => $owner, 'version' => 1, 'storage_path' => $path, 'mime_type' => 'image/png',
            'checksum' => hash('sha256', 'synthetic stamp bytes'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
