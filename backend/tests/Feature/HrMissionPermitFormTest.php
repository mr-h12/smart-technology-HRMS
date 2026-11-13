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
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrMissionPermitFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const FIELDS = [
        'job_title' => 'مهندسة اختبار',
        'mission_date' => '2026-11-15',
        'mission_reason' => 'متابعة موقع الاختبار.',
        'department_manager' => 'مدير الاختبار',
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
            ->assertJsonFragment(['type' => 'mission_permit']);
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
        $this->assertDatabaseMissing('hr_form_exports', ['form_type' => 'mission_permit', 'stage' => 'signed']);

        $role = Role::create(['slug' => 'employee', 'name' => 'Employee', 'name_ar' => 'موظف']);
        foreach ([$this->managerOf($this->site), $this->makeUser('hr', ['role_id' => $role->id])] as $user) {
            $this->actingAs($user, 'api');
            $this->generate()->assertForbidden();
            $this->getJson('/api/v1/hr-manual-form-definitions')->assertForbidden();
            $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=mission_permit")->assertForbidden();
            $this->getJson("/api/v1/hr-form-exports/{$id}")->assertForbidden();
            $this->get("/api/v1/hr-form-exports/{$id}/download")->assertForbidden();
            $this->postJson("/api/v1/hr-form-exports/{$id}/retry-manual/mission_permit")->assertForbidden();
        }
    }

    public function test_each_typed_field_is_validated_and_unknown_forms_are_refused(): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $this->generate([$field => '  '])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
        }
        foreach ([
            'job_title' => str_repeat('a', 161), 'mission_date' => '2026-02-30',
            'mission_reason' => str_repeat('a', 501), 'department_manager' => str_repeat('a', 161),
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
        $id = $this->generate(['mission_reason' => '  متابعة موقع الاختبار.  ', 'name_ar' => 'Ignored'], $key)->assertAccepted()
            ->assertJsonPath('data.serial_number', 1)->json('data.id');
        $this->generate(key: $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->generate(['mission_reason' => 'Different'], $key)->assertConflict();
        $snapshot = DB::table('hr_form_exports')->where('id', $id)->value('snapshot');
        $this->assertEquals([
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'code' => 1, 'department' => 'اختبار', 'gender' => 'male',
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
        $retry = "/api/v1/hr-form-exports/{$id}/retry-manual/mission_permit";
        $this->postJson($retry)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.serial_number', 1);
        $this->postJson($retry)->assertConflict();
        $this->render($id);
        $this->assertDatabaseCount('hr_form_exports', 1);
        $this->assertSame(1, $created->count());
        $this->generate()->assertAccepted()->assertJsonPath('data.serial_number', 2);
    }

    private function generate(array $fields = [], ?string $key = null, string $form = 'mission_permit'): TestResponse
    {
        return $this->postJson("/api/v1/employees/{$this->employeeId}/hr-forms/manual/{$form}", [
            ...self::FIELDS, ...$fields, 'idempotency_key' => $key ?? (string) Str::uuid7(),
        ]);
    }

    private function assertReadable(string $id): void
    {
        $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=mission_permit")
            ->assertOk()->assertJsonFragment(['id' => $id, 'form_type' => 'mission_permit']);
        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertOk();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
    }

    private function render(string $id): void
    {
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, '<h1>إذن مأمورية</h1>')
            && str_contains($html, 'سارة مثال للاختبار')
            && str_contains($html, 'الأحد الموافق <bdi>15/11/2026</bdi>')
            && str_contains($html, self::FIELDS['mission_reason'])
            && str_contains($html, self::FIELDS['job_title'])
            && str_contains($html, self::FIELDS['department_manager'])
            && substr_count($html, 'class="line"') === 2 && ! str_contains($html, '<img')))->willReturn('%PDF-1.7 mission permit');
        (new RenderHrForm($id))->handle($renderer);
    }
}
