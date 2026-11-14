<?php

namespace Tests\Feature;

use App\Modules\Pdf\Application\RenderPdf;
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

    public static function certificateWording(): array
    {
        return [
            'female' => ['female', 'الموظفة المذكورة بياناتها أدناه'],
            'male' => ['male', 'الموظف المذكور بياناته أدناه'],
        ];
    }

    #[DataProvider('certificateWording')]
    public function test_worker_renders_once_and_publishes_only_ready_files(string $gender, string $wording): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', ['gender' => $gender, 'national_id' => '12345678901234', 'department' => 'QA']);
        $id = $this->generate($employee)->assertAccepted()->json('data.id');
        $this->getJson("/api/v1/hr-form-exports/{$id}/download")->assertNotFound();
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, 'Synthetic QA')
            && str_contains($html, $wording) && str_contains($html, 'signature-line')))
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
        $this->getJson("/api/v1/hr-form-exports/{$id}/download")->assertForbidden();
        $this->signIn('ceo');
        $this->getJson('/api/v1/hr-form-exports?awaiting_approval=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertSame(1, DB::table('audit_log')->where('event', 'GENERATED')->where('entity_id', $id)->count());
    }

    public function test_rows_of_postponed_form_types_are_no_longer_served(): void
    {
        Storage::fake('local');
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

    public static function praises(): array
    {
        return [
            'standard, female' => ['female', null, 'وكانت من أكفأ الموظفات اللاتي عملن بالشركة، وتميزت في أداء مهامها وتمتعت بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.'],
            'standard, male' => ['male', null, 'وكان من أكفأ الموظفين الذين عملوا بالشركة، وتميز في أداء مهامه وتمتع بالتقدير والاحترام والثقة الكاملة من جميع المسؤولين لدى الشركة.'],
            'stored praise' => ['female', 'إشادة للاختبار <test>', 'إشادة للاختبار <test>'],
            'stored as none' => ['female', '', null],
        ];
    }

    #[DataProvider('praises')]
    public function test_the_view_prints_the_standard_praise_and_a_stored_praise_still_prints(string $gender, ?string $stored, ?string $printed): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', ['gender' => $gender, 'national_id' => '12345678901234', 'department' => 'QA']);
        $id = $this->generate($employee)->assertAccepted()->json('data.id');
        $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true);
        $this->assertArrayNotHasKey('praise', $snapshot);
        if ($stored !== null) {
            // a snapshot made while HR could still edit the praise
            DB::table('hr_form_exports')->where('id', $id)->update(['snapshot' => json_encode([...$snapshot, 'praise' => $stored])]);
        }
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => $printed === null
            ? ! str_contains($html, '<p class="praise">')
            : str_contains($html, '<p class="praise">'.e($printed).'</p>')))->willReturn('%PDF-1.7 synthetic');
        (new RenderPdf($id))->handle($renderer);
    }

    public function test_the_certificate_prints_the_company_logo_and_a_missing_asset_fails_loudly(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', ['national_id' => '12345678901234', 'department' => 'QA']);
        $id = $this->generate($employee)->assertAccepted()->json('data.id');
        $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('pdf/letterhead/logo.png')));
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, $logo)))
            ->willReturn('%PDF-1.7 synthetic');
        (new RenderPdf($id))->handle($renderer);

        $this->expectException(Throwable::class);
        FilePdfAssets::asset('letterhead/missing.png', 'image/png');
    }

    public function test_view_as_cannot_approve_and_approval_retains_the_serial_and_retries_the_same_export(): void
    {
        Queue::fake();
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', ['national_id' => '12345678901234', 'department' => 'QA']);
        $key = (string) Str::uuid7();
        $older = $this->generate($employee, $key)->assertAccepted()->json('data.id');
        $this->generate($employee, $key)->assertAccepted()->assertJsonPath('data.id', $older);
        $unsigned = $this->generate($employee)->assertAccepted()
            ->assertJsonPath('data.serial_number', 2)->assertJsonPath('data.regenerated_from_id', $older)->json('data.id');
        DB::table('hr_form_exports')->whereIn('id', [$older, $unsigned])->update([
            'status' => 'ready', 'sha256' => str_repeat('a', 64), 'size_bytes' => 123,
        ]);

        $ceo = $this->makeUser('ceo');
        $signature = (string) Str::uuid7();
        DB::table('hr_signature_images')->insert([
            'id' => $signature, 'owner_id' => $ceo->id, 'version' => 1, 'storage_path' => "hr-signatures/{$signature}.png",
            'mime_type' => 'image/png', 'checksum' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function generate(string $employee, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/hr-form-exports', [
            'type' => 'experience_certificate', 'subject_id' => $employee, 'idempotency_key' => $key ?? (string) Str::uuid7(),
            'fields' => ['job_title' => 'Synthetic QA'],
        ]);
    }
}
