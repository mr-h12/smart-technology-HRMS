<?php

namespace Tests\Feature;

use App\Modules\Documents\Application\EndOfServiceAmounts;
use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Domain\FormRenderer;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrEndOfServiceFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    public static function anniversaries(): array
    {
        return [
            ['2025-02-01', '2025-03-01', 0, '0.000000'],
            ['2025-02-01', '2026-01-31', 0, '0.000000'],
            ['2025-02-01', '2026-02-01', 1, '6172.835000'],
            ['2024-02-29', '2025-02-28', 0, '0.000000'],
            ['2024-02-29', '2025-03-01', 1, '6172.835000'],
            ['2020-04-15', '2025-11-01', 5, '30864.175000'],
        ];
    }

    #[DataProvider('anniversaries')]
    public function test_suggestion_uses_completed_years_and_exact_decimals(string $hire, string $end, int $years, string $amount): void
    {
        $fields = EndOfServiceAmounts::calculate(['hire_date' => $hire], [
            'last_working_day' => $end, 'wage_month' => substr($end, 0, 7),
            'last_total_wage' => '12345.67', 'final_amount' => null, 'override_reason' => null,
        ]);
        $this->assertSame($years, $fields['completed_years']);
        $this->assertSame($amount, $fields['suggested_amount']);
        $this->assertSame($amount, $fields['final_amount']);
    }

    public function test_issue_date_and_computed_and_overridden_letters(): void
    {
        $this->travelTo(Carbon::parse('2026-02-28 22:30:00', 'UTC'));
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $employee = $this->employee('permanent_worker', '2024-02-29', ['hire_date' => '2024-02-29']);
        $path = "/api/v1/employees/{$employee}/hr-forms/manual/end_of_service";
        $body = [
            'job_title' => 'Synthetic technician', 'last_working_day' => '2026-03-02',
            'last_total_wage' => '12345.67', 'wage_month' => '2026-03',
            'idempotency_key' => (string) Str::uuid7(),
        ];

        foreach ([null, '2026-02-30', '2026-03-02'] as $invalid) {
            $this->postJson($path, [...$body, 'issued_on' => $invalid])->assertUnprocessable();
        }

        foreach ([false, true] as $overridden) {
            $data = [...$body, 'issued_on' => '2026-03-01', 'idempotency_key' => (string) Str::uuid7()];
            if ($overridden) {
                $data += ['final_amount' => '12300.01', 'override_reason' => 'Synthetic adjustment'];
            }
            $id = $this->postJson($path, $data)->assertAccepted()->json('data.id');
            $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true);
            $this->assertSame('2026-03-01', $snapshot['fields']['issued_on']);
            $renderer = $this->createMock(FormRenderer::class);
            $renderer->expects($this->once())->method('pdf')->with($this->callback(function (string $html) use ($overridden): bool {
                $this->assertStringContainsString('إنه في يوم الأحد الموافق <bdi>01/03/2026</bdi>', $html);
                $this->assertStringContainsString('يعمل في الشركة منذ سنتان', $html);
                $this->assertStringContainsString('الرقم القومي / <span class="line"></span>', $html);
                $this->assertStringContainsString('تحريرًا في يوم <span class="line"></span>', $html);
                $this->assertStringContainsString('الحساب اليدوي، إن لزم / <span class="line"></span>', $html);
                $working = '2 × (12345.67 ÷ 2) = 12345.67';
                $this->assertSame(! $overridden, str_contains($html, $working));
                $amount = $overridden ? '12300.01' : '12345.67';
                $this->assertStringContainsString("للموظف المذكور هي <bdi>{$amount}</bdi> جنيه.", $html);

                return true;
            }))->willReturn('%PDF-1.7 synthetic');
            (new RenderHrForm($id))->handle($renderer);
        }
    }

    public function test_override_validation_audit_and_pay_access(): void
    {
        Queue::fake();
        $this->signIn('hr');
        $employee = $this->employee('permanent_worker', '2024-02-29', ['hire_date' => '2024-02-29']);
        $path = "/api/v1/employees/{$employee}/hr-forms/manual/end_of_service";
        $list = "/api/v1/employees/{$employee}/hr-form-exports";
        $body = [
            'job_title' => 'Synthetic technician', 'last_working_day' => '2026-03-01', 'issued_on' => '2026-03-01',
            'last_total_wage' => '12345.67', 'wage_month' => '2026-03', 'final_amount' => '12300.01',
            'override_reason' => 'Synthetic approved adjustment', 'idempotency_key' => (string) Str::uuid7(),
        ];
        $before = DB::table('employees')->get()->toJson();
        $pay = DB::table('employee_pay_changes')->get()->toJson();
        $failed = $this->postJson($path, [...$body, 'override_reason' => null])->assertUnprocessable();
        $logged = json_decode(DB::table('request_log')->where('request_id', $failed->headers->get('X-Request-Id'))->value('request_body'), true);
        $this->assertSame('[masked]', $logged['last_total_wage']);
        $this->assertSame('[masked]', $logged['final_amount']);
        foreach ([['last_working_day' => '2024-02-28'], ['last_working_day' => '2099-01-01'], ['wage_month' => '2026-04'], ['last_total_wage' => '0']] as $invalid) {
            $this->postJson($path, [...$body, ...$invalid])->assertUnprocessable();
        }
        $id = $this->postJson($path, $body)->assertAccepted()->json('data.id');
        $this->postJson($path, $body)->assertAccepted()->assertJsonPath('data.id', $id);
        $file = "/api/v1/hr-form-exports/{$id}";
        $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true)['fields'];
        $this->assertSame('12345.670000', $snapshot['suggested_amount']);
        $this->assertSame('12300.010000', $snapshot['final_amount']);
        $this->assertSame($body['override_reason'], $snapshot['override_reason']);
        $audit = json_decode(DB::table('audit_log')->where('entity_id', $id)->where('event', 'CREATED')->value('new_values'), true);
        $this->assertArrayNotHasKey('fields', $audit);
        $this->assertContains('last_total_wage', $audit['field_names']);
        (new RenderHrForm($id))->failed(null);
        $this->postJson($file.'/retry-manual/end_of_service')->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertSame($before, DB::table('employees')->get()->toJson());
        $this->assertSame($pay, DB::table('employee_pay_changes')->get()->toJson());
        $this->signIn('ceo');
        $this->postJson($file.'/approve')->assertConflict();
        $this->assertDatabaseMissing('hr_form_exports', ['stage' => 'signed']);

        $role = Role::create(['slug' => 'eos-no-pay', 'name' => 'No Pay', 'name_ar' => 'اختبار']);
        $role->permissions()->attach(Permission::where('resource', 'hr_forms')->whereIn('action', ['generate', 'view'])
            ->where('scope', 'all')->pluck('id')->all());
        $this->actingAs($this->makeUser('hr', ['role_id' => $role->id]), 'api');
        $this->postJson($path, $body)->assertForbidden();
        $this->getJson($list.'?form_type=end_of_service')->assertForbidden();
        $this->getJson($list)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($file)->assertForbidden();
        $this->get($file.'/download')->assertForbidden();
        $this->postJson($file.'/retry-manual/end_of_service')->assertForbidden();
    }
}
