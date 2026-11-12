<?php

namespace Tests\Feature;

use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Domain\FormRenderer;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrLatePermitFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const FIELDS = [
        'addressee' => 'مدير الاختبار',
        'permit_date' => '2026-11-16',
        'start_time' => '09:00',
        'end_time' => '11:00',
        'reason' => 'سبب تجريبي',
        'job_title' => 'مهندسة اختبار',
        'responsible_manager' => 'مسؤول تجريبي',
        'issued_on' => '2026-11-15',
    ];

    private string $employeeId;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $this->site = $this->workSite();
        $this->employeeId = $this->employee('permanent_worker', '2026-01-01', [
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'department' => 'اختبار',
        ]);
        $this->homeSite($this->employeeId, $this->site, '2026-01-01');
    }

    public function test_role_matrix_and_unsigned_only_approval(): void
    {
        $this->getJson('/api/v1/hr-manual-form-definitions')->assertOk()
            ->assertJsonPath('data.forms.0.type', 'late_permit')->assertJsonCount(8, 'data.forms.0.fields');
        $id = $this->generate()->assertAccepted()->json('data.id');
        $this->render($id);
        $this->assertReadable($id);

        $this->signIn('developer');
        $this->generate()->assertAccepted();
        $this->assertReadable($id);

        $this->signIn('ceo');
        $this->generate()->assertForbidden();
        $this->assertReadable($id);
        $this->postJson("/api/v1/hr-form-exports/{$id}/approve")->assertConflict();
        $this->assertDatabaseMissing('hr_form_exports', ['form_type' => 'late_permit', 'stage' => 'signed']);

        $role = Role::create(['slug' => 'employee', 'name' => 'Employee', 'name_ar' => 'موظف']);
        foreach ([$this->managerOf($this->site), $this->makeUser('hr', ['role_id' => $role->id])] as $user) {
            $this->actingAs($user, 'api');
            $this->generate()->assertForbidden();
            $this->getJson('/api/v1/hr-manual-form-definitions')->assertForbidden();
            $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=late_permit")->assertForbidden();
            $this->getJson("/api/v1/hr-form-exports/{$id}")->assertForbidden();
            $this->get("/api/v1/hr-form-exports/{$id}/download")->assertForbidden();
            $this->postJson("/api/v1/hr-form-exports/{$id}/retry-manual/late_permit")->assertForbidden();
        }
    }

    public function test_each_typed_field_is_validated_and_unknown_forms_are_refused(): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $this->generate([$field => '  '])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
        }
        foreach ([
            'addressee' => str_repeat('a', 161), 'permit_date' => '2026-02-30', 'start_time' => '25:00',
            'end_time' => '08:59', 'reason' => str_repeat('a', 501), 'job_title' => str_repeat('a', 161),
            'responsible_manager' => str_repeat('a', 161), 'issued_on' => '15/11/2026',
        ] as $field => $invalid) {
            $this->generate([$field => $invalid])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
        }
        $this->generate(form: 'unknown')->assertNotFound();
        $this->postJson('/api/v1/hr-form-exports/'.Str::uuid7().'/retry-manual/unknown')->assertNotFound();
        $this->assertDatabaseCount('hr_form_exports', 0);
    }

    public function test_snapshot_audit_idempotency_and_retry_without_business_record_changes(): void
    {
        $employee = (array) DB::table('employees')->where('id', $this->employeeId)->firstOrFail();
        $key = (string) Str::uuid7();
        $id = $this->generate(['reason' => '  سبب تجريبي  ', 'name_ar' => 'Ignored'], $key)->assertAccepted()
            ->assertJsonPath('data.serial_number', 1)->json('data.id');
        $this->generate(key: $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->generate(['reason' => 'Different'], $key)->assertConflict();
        $snapshot = DB::table('hr_form_exports')->where('id', $id)->value('snapshot');
        $this->assertEquals([
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'code' => 1, 'department' => 'اختبار',
            'fields' => self::FIELDS,
        ], json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR));
        $created = DB::table('audit_log')->where(['event' => 'CREATED', 'entity_type' => 'hr_form_exports', 'entity_id' => $id]);
        $this->assertSame(1, $created->count());
        $this->assertEquals(self::FIELDS, json_decode($created->value('new_values'), true, flags: JSON_THROW_ON_ERROR)['fields']);
        $this->assertSame($employee, (array) DB::table('employees')->where('id', $this->employeeId)->firstOrFail());
        $this->assertDatabaseCount('attendance_days', 0);
        $this->assertDatabaseCount('leave_requests', 0);
        DB::table('employees')->where('id', $this->employeeId)->update(['name_ar' => 'Changed after export']);

        (new RenderHrForm($id))->failed(null);
        $retry = "/api/v1/hr-form-exports/{$id}/retry-manual/late_permit";
        $this->postJson($retry)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.serial_number', 1);
        $this->postJson($retry)->assertConflict();
        $this->render($id);
        $this->assertDatabaseCount('hr_form_exports', 1);
        $this->assertSame(1, $created->count());
        $this->generate()->assertAccepted()->assertJsonPath('data.serial_number', 2);
    }

    public static function permitDurations(): array
    {
        return [
            '90 minutes' => ['10:30', 'ساعة واحدة و30 دقيقة'],
            '120 minutes' => ['11:00', 'ساعتان'],
        ];
    }

    #[DataProvider('permitDurations')]
    public function test_prints_duration_from_entered_times(string $end, string $duration): void
    {
        $id = $this->generate(['end_time' => $end])->assertAccepted()->json('data.id');
        $this->render($id, $duration);
    }

    private function generate(array $fields = [], ?string $key = null, string $form = 'late_permit'): TestResponse
    {
        return $this->postJson("/api/v1/employees/{$this->employeeId}/hr-forms/manual/{$form}", [
            ...self::FIELDS, ...$fields, 'idempotency_key' => $key ?? (string) Str::uuid7(),
        ]);
    }

    private function assertReadable(string $id): void
    {
        $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=late_permit")
            ->assertOk()->assertJsonFragment(['id' => $id, 'form_type' => 'late_permit']);
        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertOk();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
    }

    private function render(string $id, string $duration = 'ساعتان'): void
    {
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, 'إذن تأخير')
            && str_contains($html, 'سارة مثال للاختبار') && str_contains($html, 'لمدة '.$duration)
            && str_contains($html, 'للأسباب التالية:')
            && str_contains($html, 'الاثنين الموافق <bdi>16/11/2026</bdi>')
            && str_contains($html, 'الأحد الموافق <bdi>15/11/2026</bdi>')
            && substr_count($html, 'class="line"') === 3 && ! str_contains($html, '<img')))->willReturn('%PDF-1.7 late permit');
        (new RenderHrForm($id))->handle($renderer);
    }
}
