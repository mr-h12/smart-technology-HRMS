<?php

namespace Tests\Feature;

use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Domain\FormRenderer;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrAdvanceRequestFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const DATE = '2026-10-22';

    private const SALARY = '8765.432100';

    private const FIELDS = ['job_title' => 'Synthetic technician', 'reason' => 'Synthetic reason',
        'repayment_method' => 'salary_deduction', 'previous_advance' => true, 'finance_remarks' => 'Synthetic remarks'];

    private string $site;

    private string $employeeId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo(self::DATE.' 19:00');
        Queue::fake();
        Storage::fake('local');
        $this->signIn('hr');
        $this->site = $this->workSite();
        $this->manager = $this->managerOf($this->site);
        $this->employeeId = $this->employee('permanent_worker', '2026-01-01', [
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'department' => 'Synthetic QA', 'salary' => self::SALARY,
        ]);
        $this->homeSite($this->employeeId, $this->site, '2026-01-01');
    }

    public function test_real_roles_and_pay_scope_on_generation_status_and_download(): void
    {
        $request = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $export = $this->generate($request)->assertAccepted()->json('data.id');
        $this->render($export);
        $this->assertView($export);
        $this->signIn('ceo');
        $this->generate($request)->assertForbidden();
        $this->assertView($export);
        $this->postJson("/api/v1/hr-form-exports/{$export}/approve")->assertConflict();
        $this->signIn('developer');
        $this->generate($request)->assertAccepted();
        $this->assertView($export);

        $this->actingAs($this->manager, 'api');
        $this->getJson("/api/v1/employees/{$this->employeeId}")->assertOk()->assertJsonMissingPath('data.current_pay');
        $this->generate($request)->assertForbidden();
        $this->getJson("/api/v1/hr-form-exports/{$export}")->assertForbidden();
        $this->getJson("/api/v1/hr-form-exports/{$export}/download")->assertForbidden();

        $role = Role::create(['slug' => 'synthetic-forms-own', 'name' => 'Synthetic', 'name_ar' => 'اختبار']);
        $role->permissions()->attach(Permission::where(fn ($q) => $q->where('resource', 'hr_forms')->whereIn('action', ['generate', 'view']))
            ->orWhere(fn ($q) => $q->where('resource', 'employee')->where('action', 'view')->where('scope', 'own'))->pluck('id'));
        $this->manager->update(['role_id' => $role->id]);
        $this->actingAs($this->manager, 'api');
        $this->generate($request)->assertForbidden();
        $this->getJson("/api/v1/hr-form-exports/{$export}")->assertForbidden();
        $this->getJson("/api/v1/hr-form-exports/{$export}/download")->assertForbidden();
        $this->getJson("/api/v1/attendance/requests/{$request}/hr-form-exports")->assertForbidden();
        $this->getJson("/api/v1/employees/{$this->employeeId}/hr-form-exports")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_shared_export_routes_allow_certificate_download_without_pay_permission(): void
    {
        $request = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $advance = $this->generate($request)->assertAccepted()->json('data.id');
        $this->render($advance);

        $employee = $this->employee('office', '2026-01-01', ['national_id' => '12345678901234', 'department' => 'Synthetic QA']);
        $certificate = $this->postJson("/api/v1/employees/{$employee}/hr-forms/experience-certificate", [
            'idempotency_key' => (string) Str::uuid7(), 'job_title' => 'Synthetic technician',
        ])->assertAccepted()->json('data.id');
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->willReturn('%PDF-1.7 synthetic certificate');
        (new RenderHrForm($certificate))->handle($renderer);

        $role = Role::create(['slug' => 'synthetic-forms-reader', 'name' => 'Synthetic forms reader', 'name_ar' => 'قارئ النماذج']);
        $role->permissions()->attach(Permission::where(['resource' => 'hr_forms', 'action' => 'view', 'scope' => 'all'])->sole()->id);
        $this->actingAs($this->makeUser('hr', ['role_id' => $role->id]), 'api');
        $this->getJson("/api/v1/hr-form-exports/{$advance}")->assertForbidden();
        $this->get("/api/v1/hr-form-exports/{$advance}/download")->assertForbidden();
        $this->getJson("/api/v1/hr-form-exports/{$certificate}")->assertOk();
        $this->assertSame('%PDF-1.7 synthetic certificate', $this->get("/api/v1/hr-form-exports/{$certificate}/download")->assertOk()->streamedContent());
    }

    public function test_shared_approve_route_never_creates_a_signed_advance(): void
    {
        $request = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $export = $this->generate($request)->assertAccepted()->json('data.id');
        $this->render($export);
        $this->signIn('ceo');
        $this->postJson("/api/v1/hr-form-exports/{$export}/approve")->assertConflict();
        $this->assertDatabaseMissing('hr_form_exports', ['form_type' => 'advance_request', 'stage' => 'signed']);
        $this->assertDatabaseCount('hr_form_exports', 1);
    }

    public function test_snapshot_uses_effective_pay_and_refused_requests_can_be_printed_and_retried(): void
    {
        $id = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        app(EmployeeService::class)->addPayChange($this->employeeId, ['effective_from' => '2026-10-23', 'salary' => '11000']);
        $key = (string) Str::uuid7();
        $this->generate($id, ['paper_decision_date' => self::DATE])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'paper_decision_date');
        $first = $this->generate($id, key: $key)->assertAccepted()->assertJsonPath('data.serial_number', 1)->json('data.id');
        $this->generate($id, key: $key)->assertAccepted()->assertJsonPath('data.id', $first);
        $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $first)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $expected = [...self::FIELDS, 'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'code' => 1,
            'department' => 'Synthetic QA', 'hire_date' => '2026-01-01', 'request_date' => self::DATE, 'salary' => self::SALARY, 'amount' => '250.500000'];
        $this->assertEquals($expected, array_intersect_key($snapshot, $expected));
        $this->postJson("/api/v1/attendance/requests/{$id}/refuse", ['version' => 1, 'reason' => 'Synthetic refusal', 'paper_decision_date' => self::DATE])->assertOk();
        $second = $this->generate($id)->assertAccepted()->assertJsonPath('data.serial_number', 2)->json('data.id');
        (new RenderHrForm($second))->failed(null);
        $retry = "/api/v1/hr-form-exports/{$second}/retry-advance-request";
        $this->postJson($retry)->assertAccepted()->assertJsonPath('data.id', $second);
        $this->postJson($retry)->assertConflict();
        $this->render($second);
        $this->assertSame(2, DB::table('hr_form_exports')->where('form_type', 'advance_request')->count());
        $audit = DB::table('audit_log')->where('entity_id', $second)->where('event', 'GENERATED')->sole();
        $this->assertStringNotContainsString('salary', (string) $audit->new_values);
        $this->assertStringNotContainsString(self::SALARY, (string) $audit->new_values);
    }

    public static function paperDecisions(): array
    {
        return [['approve', self::DATE], ['refuse', self::DATE], ['approve', null]];
    }

    #[DataProvider('paperDecisions')]
    public function test_paper_date_is_recorded_by_the_existing_decision_and_audited(string $action, ?string $date): void
    {
        $id = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $hr = $this->signIn('hr');
        $this->postJson("/api/v1/attendance/requests/{$id}/{$action}", [
            'version' => 1, 'paper_decision_date' => $date, ...($action === 'refuse' ? ['reason' => 'Synthetic refusal'] : []),
        ])->assertOk();
        $this->assertDatabaseHas('attendance_requests', ['id' => $id, 'decided_by' => $hr->id, 'paper_decision_date' => $date]);
        $detail = $this->getJson("/api/v1/attendance/requests/{$id}")->assertOk()->assertJsonPath('data.paper_decision_date', $date);
        $this->assertNotNull($detail->json('data.decided_at'));
        if ($date !== null) {
            $row = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->sole();
            $this->assertSame($date, json_decode($row->new_values, true, flags: JSON_THROW_ON_ERROR)['paper_decision_date']);
        }
    }

    public function test_invalid_paper_dates_are_rejected_without_recording_a_decision(): void
    {
        $this->file(['paper_decision_date' => self::DATE])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'rows.0.paper_decision_date');
        $id = $this->file()->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $path = "/api/v1/attendance/requests/{$id}/approve";
        foreach (['2026-10-23' => 'The paper decision date cannot be after today in Cairo.',
            '2026-10-21' => 'The paper decision date cannot be before the request date.'] as $date => $message) {
            $this->postJson($path, ['version' => 1, 'paper_decision_date' => $date], ['Accept-Language' => 'en'])
                ->assertUnprocessable()->assertJsonFragment(['message' => $message]);
        }
        $this->postJson($path, ['version' => 1, 'paper_decision_date' => '2026-10-23'], ['Accept-Language' => 'ar'])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'لا يمكن أن يكون تاريخ القرار الورقي بعد اليوم بتوقيت القاهرة.']);
        $overtime = (string) Str::uuid7();
        DB::table('attendance_requests')->insert(['id' => $overtime, 'employee_id' => $this->employeeId, 'site_id' => $this->site, 'date' => self::DATE,
            'type' => 'overtime', 'minutes' => 60, 'in_time' => '09:00', 'out_time' => '17:00', 'requested_by' => $this->manager->id]);
        $this->postJson("/api/v1/attendance/requests/{$overtime}/approve", ['version' => 1, 'paper_decision_date' => self::DATE], ['Accept-Language' => 'en'])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Only an advance decision can include a paper decision date.']);
        foreach ([$id, $overtime] as $request) {
            $this->assertDatabaseHas('attendance_requests', ['id' => $request, 'status' => 'pending', 'decided_at' => null, 'paper_decision_date' => null]);
        }
    }

    private function file(array $extra = []): TestResponse
    {
        return $this->actingAs($this->manager, 'api')->postJson('/api/v1/attendance/site-day', [
            'site_id' => $this->site, 'date' => self::DATE,
            'rows' => [['employee_id' => $this->employeeId, 'site_id' => $this->site, 'in_time' => '09:00',
                'out_time' => null, 'version' => null, 'advance_request' => '250.5', ...$extra]],
        ]);
    }

    private function generate(string $id, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/attendance/requests/{$id}/hr-forms/advance-request", [
            ...self::FIELDS, 'idempotency_key' => $key ?? (string) Str::uuid7(), ...$extra,
        ]);
    }

    private function assertView(string $id): void
    {
        $this->getJson("/api/v1/hr-form-exports/{$id}")->assertOk()->assertJsonMissingPath('data.salary')->assertJsonMissingPath('data.snapshot');
        $this->get("/api/v1/hr-form-exports/{$id}/download")->assertOk();
    }

    private function render(string $id): void
    {
        $renderer = $this->createMock(FormRenderer::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, '8765.43')
            && str_contains($html, '250.50') && str_contains($html, '22/10/2026') && str_contains($html, '01/01/2026')
            && str_contains($html, 'Finance &amp; Administration') && ! str_contains($html, '<img src=')))->willReturn('%PDF-1.7 advance');
        $job = new RenderHrForm($id);
        $job->handle($renderer);
        $job->handle($renderer);
    }
}
