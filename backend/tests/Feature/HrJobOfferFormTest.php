<?php

namespace Tests\Feature;

use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class HrJobOfferFormTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/hr-forms/manual-candidate/job_offer';

    private const FIELDS = [
        'issued_on' => '2027-02-01', 'candidate_name' => 'Synthetic Candidate', 'address' => 'Synthetic Test Street',
        'job_title' => 'Synthetic Engineer', 'monthly_salary' => '12345.6', 'evaluation_months' => '4',
        'reply_deadline' => '2027-02-05', 'start_date' => '2027-02-08',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
    }

    public function test_candidate_snapshot_retry_and_no_employee_or_pay_changes(): void
    {
        $before = $this->businessData();
        $key = (string) Str::uuid7();
        $id = $this->generate(['idempotency_key' => $key])->assertAccepted()->assertJsonPath('data.employee_id', null)->json('data.id');
        $this->generate(['idempotency_key' => $key, 'monthly_salary' => '12345.60'])->assertAccepted()->assertJsonPath('data.id', $id);
        $this->generate(['idempotency_key' => $key, 'address' => 'Different'])->assertConflict();
        $export = DB::table('hr_form_exports')->where('id', $id)->sole();
        $this->assertEquals(['fields' => [...self::FIELDS, 'monthly_salary' => '12345.60', 'evaluation_months' => 4]], json_decode($export->snapshot, true));
        $audit = DB::table('audit_log')->where('entity_id', $id)->where('event', 'CREATED')->sole();
        $names = array_keys(self::FIELDS);
        sort($names);
        $this->assertEquals(['form_type' => 'job_offer', 'serial_number' => 1, 'field_names' => $names], json_decode($audit->new_values, true));
        (new RenderHrForm($id))->failed(null);
        $this->postJson("/api/v1/hr-form-exports/{$id}/retry-manual/job_offer")->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('hr_form_exports', 1);
        $this->assertSame($before, $this->businessData());
    }

    public function test_validation_and_failed_request_masking(): void
    {
        foreach (array_keys(self::FIELDS) as $field) {
            $this->generate([$field => null])->assertUnprocessable();
        }
        foreach ([
            ['monthly_salary' => '0.00'], ['monthly_salary' => '1.001'], ['monthly_salary' => 123.45],
            ['evaluation_months' => 0], ['reply_deadline' => '2027-01-30'], ['start_date' => '2027-02-04'],
        ] as $invalid) {
            $this->generate($invalid)->assertUnprocessable();
        }
        $response = $this->generate(['start_date' => 'invalid'])->assertUnprocessable();
        $logged = DB::table('request_log')->where('request_id', $response->headers->get('X-Request-Id'))->value('request_body');
        $this->assertSame('[masked]', json_decode($logged, true)['monthly_salary']);
        $this->assertSame('[masked]', json_decode($logged, true)['address']);
        $this->assertDatabaseCount('hr_form_exports', 0);
    }

    public function test_candidate_routes_and_shared_routes_require_pay_visibility(): void
    {
        $id = $this->generate()->assertAccepted()->json('data.id');
        $file = "/api/v1/hr-form-exports/{$id}";
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson($file)->assertOk();
        $this->postJson('/api/v1/hr-forms/manual-candidate/sim_receipt')->assertNotFound();
        $this->postJson('/api/v1/employees/'.Str::uuid7().'/hr-forms/manual/job_offer')->assertNotFound();

        $row = DB::table('hr_form_exports')->where('id', $id)->sole();
        Storage::disk('local')->put($row->storage_path, '%PDF-1.7');
        DB::table('hr_form_exports')->where('id', $id)->update([
            'status' => 'ready', 'sha256' => hash('sha256', '%PDF-1.7'), 'size_bytes' => 8,
        ]);
        $this->get($file.'/download')->assertOk();
        $this->signIn('ceo');
        $this->postJson($file.'/approve')->assertConflict();
        $this->assertDatabaseMissing('hr_form_exports', ['stage' => 'signed']);

        $role = Role::create(['slug' => 'no-pay', 'name' => 'No Pay', 'name_ar' => 'اختبار']);
        $role->permissions()->attach(Permission::where('resource', 'hr_forms')->whereIn('action', ['generate', 'view'])
            ->where('scope', 'all')->pluck('id')->all());
        $this->actingAs($this->makeUser('hr', ['role_id' => $role->id]), 'api');
        $this->generate()->assertForbidden();
        $this->getJson(self::PATH)->assertForbidden();
        $this->getJson($file)->assertForbidden();
        $this->get($file.'/download')->assertForbidden();
        $this->postJson($file.'/retry-manual/job_offer')->assertForbidden();
    }

    private function generate(array $overrides = []): TestResponse
    {
        return $this->postJson(self::PATH, [...self::FIELDS, 'idempotency_key' => (string) Str::uuid7(), ...$overrides]);
    }

    private function businessData(): array
    {
        $data = [];
        foreach (['employees', 'employee_pay_changes'] as $table) {
            $data[$table] = DB::table($table)->get()->toJson();
        }

        return $data;
    }
}
