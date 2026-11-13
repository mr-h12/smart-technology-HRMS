<?php

namespace Tests\Feature;

use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Domain\FormRenderer;
use App\Modules\Leave\Application\LeaveBalances;
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

    public static function kinds(): array
    {
        return [
            ['annual', 'female', 'اعتيادية', 'مقدمة لسيادتكم'],
            ['casual', 'male', 'عارضة', 'مقدم لسيادتكم'],
            ['sick', 'female', 'مرضي', 'مقدمة لسيادتكم'],
            ['unpaid', 'male', 'بدون أجر', 'مقدم لسيادتكم'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_mapping_scope_and_render(string $kind, string $gender, string $label, string $wording): void
    {
        $hr = $this->signIn('hr');
        $employee = $this->employee('office', '2020-01-01', ['gender' => $gender, 'department' => 'Test department']);
        $request = $this->submit($employee, $kind);
        $this->signIn('manager');
        $this->postJson($this->path($request), self::FIELDS)->assertForbidden();
        $this->actingAs($hr, 'api');
        $key = (string) Str::uuid7();
        $id = $this->generate($request, $key);
        $this->assertSame($id, $this->generate($request, $key));
        $snapshot = $this->snapshot($id);
        $expected = [...self::FIELDS, 'kind' => $kind, 'gender' => $gender, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05',
            'days' => 1, 'reason' => 'Test leave reason', 'department' => 'Test department',
            'balance' => app(LeaveBalances::class)->forEmployee($employee, 2026)['balance']];
        $this->assertEquals($expected, array_intersect_key($snapshot, $expected));
        $this->render($id, fn (string $html): bool => str_contains($html, $label.' <span class="circle marked">')
            && str_contains($html, $wording) && str_contains($html, 'يوم واحد')
            && substr_count($html, 'الخميس الموافق <bdi>05/11/2026</bdi>') === 2
            && str_contains($html, 'رصيد سنة <bdi>2026</bdi>')
            && ! str_contains($html, '2026-11-05') && ! str_contains($html, '<img src='));
        $this->postJson("/api/v1/hr-form-exports/{$id}/approve-leave-request")->assertConflict();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
        $this->signIn('manager');
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertForbidden();
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
        $approve = "/api/v1/hr-form-exports/{$unsigned}/approve-leave-request";
        $this->postJson("/api/v1/hr-form-exports/{$older}/approve-leave-request")->assertConflict();
        $this->signIn('hr');
        $this->postJson($approve)->assertForbidden();
        $this->actingAs($hr, 'api');
        $signature = $this->signature($hr->id);
        $signed = $this->postJson($approve)->assertAccepted()->assertJsonPath('data.serial_number', 2)->json('data.id');
        $this->assertSame('hr', $this->snapshot($signed)['stamp_line']);
        $this->assertSame($hr->id, $this->snapshot($signed)['decider_id']);
        $this->assertSame(1, $this->snapshot($signed)['signature_version']);
        (new RenderHrForm($signed))->failed(null);
        $this->postJson("/api/v1/hr-form-exports/{$signed}/retry-leave-request")->assertAccepted()->assertJsonPath('data.id', $signed);
        $this->assertDatabaseHas('hr_form_exports', ['id' => $signed, 'signature_image_id' => $signature]);
        $this->render($signed, fn (string $html): bool => (bool) preg_match('/data-stamp-line="hr">\s*<div>[^<]+<\/div>\s*<img/', $html));
        $this->decision($request, 'cancel')->assertOk();
        $this->assertSame('%PDF-1.7 leave', $this->get("/api/v1/hr-form-exports/{$signed}/download")->assertOk()->streamedContent());
        $after = $this->generate($request);
        $this->render($after);
        $this->postJson("/api/v1/hr-form-exports/{$after}/approve-leave-request")->assertConflict();
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
        $this->postJson("/api/v1/hr-form-exports/{$refused}/approve-leave-request")->assertConflict();
        $this->decision($request, 'escalate')->assertOk();
        $this->assertDatabaseHas('leave_requests', ['id' => $request, 'escalated_by' => $hr->id]);
        $unsigned = $this->generate($request);
        $this->render($unsigned);
        $this->postJson("/api/v1/hr-form-exports/{$unsigned}/approve-leave-request")->assertConflict();
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
        $this->withToken($view)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve-leave-request")->assertForbidden();
        $this->assertDatabaseHas('hr_form_exports', ['id' => $unsigned, 'approved_by' => null, 'signature_image_id' => null]);
        app('auth')->forgetGuards();
        $own = $this->postJson('/api/v1/auth/login', ['email' => $ceo->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $signed = $this->withToken($own)->postJson("/api/v1/hr-form-exports/{$unsigned}/approve-leave-request")
            ->assertAccepted()->json('data.id');
        $this->assertSame('company_manager', $this->snapshot($signed)['stamp_line']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $signed, 'event' => 'APPROVED', 'user_id' => $ceo->id, 'on_behalf_of' => null]);
        $this->render($signed, fn (string $html): bool => (bool) preg_match('/data-stamp-line="company_manager">\s*<div>[^<]+<\/div>\s*<img/', $html));
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

    private function path(string $request): string
    {
        return "/api/v1/leave/requests/{$request}/hr-forms/leave-request";
    }

    private function generate(string $request, ?string $key = null): string
    {
        return $this->postJson($this->path($request), [...self::FIELDS, 'idempotency_key' => $key ?? (string) Str::uuid7()])
            ->assertAccepted()->json('data.id');
    }

    private function snapshot(string $id): array
    {
        return json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function render(string $id, ?callable $assertHtml = null): void
    {
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback($assertHtml ?? fn (string $html) => str_contains($html, 'نموذج طلب إجازة')))->willReturn('%PDF-1.7 leave');
        (new RenderHrForm($id))->handle($renderer);
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
