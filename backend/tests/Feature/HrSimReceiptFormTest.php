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

final class HrSimReceiptFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const FIELDS = ['sim_number' => '01000000000', 'issued_on' => '2026-11-15'];

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
            ->assertJsonFragment(['type' => 'sim_receipt']);
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
        $this->assertDatabaseMissing('hr_form_exports', ['form_type' => 'sim_receipt', 'stage' => 'signed']);

        $role = Role::create(['slug' => 'employee', 'name' => 'Employee', 'name_ar' => 'موظف']);
        foreach ([$this->managerOf($this->site), $this->makeUser('hr', ['role_id' => $role->id])] as $user) {
            $this->actingAs($user, 'api');
            $this->generate()->assertForbidden();
            $this->getJson('/api/v1/hr-manual-form-definitions')->assertForbidden();
            $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=sim_receipt")->assertForbidden();
            $this->getJson("/api/v1/hr-form-exports/{$id}")->assertForbidden();
            $this->get("/api/v1/hr-form-exports/{$id}/download")->assertForbidden();
            $this->postJson("/api/v1/hr-form-exports/{$id}/retry-manual/sim_receipt")->assertForbidden();
        }
    }

    public function test_each_typed_field_is_validated_and_unknown_forms_are_refused(): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $this->generate([$field => null])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
        }
        foreach ([
            'sim_number' => '02000000000', 'issued_on' => '2026-02-30',
        ] as $field => $invalid) {
            $this->generate([$field => $invalid])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
        }
        $this->generate(form: 'unknown')->assertNotFound();
        $this->postJson('/api/v1/hr-form-exports/'.Str::uuid7().'/retry-manual/unknown')->assertNotFound();
        $this->assertDatabaseCount('hr_form_exports', 0);
    }

    public function test_snapshot_audit_idempotency_and_retry_without_business_record_changes(): void
    {
        $before = $this->unrelatedTableDigests();
        $key = (string) Str::uuid7();
        $id = $this->generate(['sim_number' => '+201000000000', 'name_ar' => 'Ignored'], $key)->assertAccepted()
            ->assertJsonPath('data.serial_number', 1)->json('data.id');
        $this->generate(key: $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->generate(['sim_number' => '01000000001'], $key)->assertConflict();
        $snapshot = DB::table('hr_form_exports')->where('id', $id)->value('snapshot');
        $this->assertEquals([
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'code' => 1, 'department' => 'اختبار', 'gender' => 'male',
            'fields' => self::FIELDS,
        ], json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR));
        $created = DB::table('audit_log')->where(['event' => 'CREATED', 'entity_type' => 'hr_form_exports', 'entity_id' => $id]);
        $this->assertSame(1, $created->count());
        $fieldNames = array_keys(self::FIELDS);
        sort($fieldNames);
        $this->assertEquals([
            'form_type' => 'sim_receipt', 'serial_number' => 1, 'field_names' => $fieldNames,
        ], json_decode($created->value('new_values'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->unrelatedTableDigests());
        DB::table('employees')->where('id', $this->employeeId)->update(['name_ar' => 'Changed after export']);

        (new RenderHrForm($id))->failed(null);
        $retry = "/api/v1/hr-form-exports/{$id}/retry-manual/sim_receipt";
        $this->postJson($retry)->assertAccepted()->assertJsonPath('data.id', $id)->assertJsonPath('data.serial_number', 1);
        $this->postJson($retry)->assertConflict();
        $this->render($id);
        $this->assertDatabaseCount('hr_form_exports', 1);
        $this->assertSame(1, $created->count());
        $this->generate()->assertAccepted()->assertJsonPath('data.serial_number', 2);
    }

    public function test_validation_failure_does_not_log_sim_number(): void
    {
        $response = $this->generate(['issued_on' => 'invalid'])->assertUnprocessable();
        $body = DB::table('request_log')->where('request_id', $response->headers->get('X-Request-Id'))->value('request_body');
        $logged = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('[masked]', $logged['sim_number']);
    }

    /** @return array<string, string|null> */
    private function unrelatedTableDigests(): array
    {
        $tables = DB::table('pg_tables')->where('schemaname', 'public')->whereNotIn('tablename', [
            'audit_log', 'request_log', 'hr_form_exports', 'hr_form_serial_counters',
        ])->orderBy('tablename')->pluck('tablename');
        $digests = [];
        foreach ($tables as $table) {
            $quotedTable = DB::connection()->getQueryGrammar()->wrapTable($table);
            $digests[$table] = DB::selectOne("select md5(string_agg(payload, chr(10) order by payload)) as digest
                from (select row_to_json(t)::text as payload from {$quotedTable} t) rows")->digest;
        }

        return $digests;
    }

    private function generate(array $fields = [], ?string $key = null, string $form = 'sim_receipt'): TestResponse
    {
        return $this->postJson("/api/v1/employees/{$this->employeeId}/hr-forms/manual/{$form}", [
            ...self::FIELDS, ...$fields, 'idempotency_key' => $key ?? (string) Str::uuid7(),
        ]);
    }

    private function assertReadable(string $id): void
    {
        $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports?form_type=sim_receipt")
            ->assertOk()->assertJsonFragment(['id' => $id, 'form_type' => 'sim_receipt']);
        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertOk();
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
    }

    private function render(string $id): void
    {
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => substr_count($html, '<h1>استلام خط موبايل</h1>') === 1
            && str_contains($html, 'سارة مثال للاختبار') && str_contains($html, '01000000000')
            && str_contains($html, '15/11/2026') && ! str_contains($html, '<img')))->willReturn('%PDF-1.7 SIM receipt');
        (new RenderHrForm($id))->handle($renderer);
    }
}
