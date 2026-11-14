<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\RenderPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The one HR-form API (`hr-form-exports`), every type through the same six routes. */
final class PdfApiTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const SALARY = '8765.432100';

    private const API = '/api/v1/hr-form-exports';

    private ?string $site = null;

    private int $nationalIds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        Queue::fake();
        Storage::fake('local');
    }

    public function test_the_api_is_six_routes_and_the_signature_routes_are_unchanged(): void
    {
        $routes = fn (string $path): array => collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_contains($route->uri(), $path))
            ->map(fn (RouteDefinition $route) => implode(' ', [$route->methods()[0], $route->uri(),
                ...array_filter($route->middleware(), fn (string $m) => str_starts_with($m, 'permission:'))]))
            ->values()->all();

        $this->assertEqualsCanonicalizing([
            'GET api/v1/hr-form-exports permission:hr_forms.view',
            'POST api/v1/hr-form-exports permission:hr_forms.generate',
            'GET api/v1/hr-form-exports/{export} permission:hr_forms.view',
            'GET api/v1/hr-form-exports/{export}/download permission:hr_forms.view',
            'POST api/v1/hr-form-exports/{export}/approve permission:hr_forms.approve,hr_forms.approve_leave_request',
            'POST api/v1/hr-form-exports/{export}/retry permission:hr_forms.generate,hr_forms.approve,hr_forms.approve_leave_request',
        ], $routes('hr-form'));
        $this->assertEqualsCanonicalizing([
            'GET api/v1/me/hr-signature permission:hr_signatures.manage',
            'GET api/v1/me/hr-signature/file permission:hr_signatures.manage',
            'POST api/v1/me/hr-signature permission:hr_signatures.manage',
        ], $routes('hr-signature'));
        $this->signIn('hr');
        $this->getJson(self::API.'/not-a-uuid')->assertNotFound();
    }

    public function test_every_route_refuses_a_caller_without_its_permission(): void
    {
        $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $id = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $routes = [['GET', self::API], ['POST', self::API], ['GET', self::API."/{$id}"], ['GET', self::API."/{$id}/download"],
            ['POST', self::API."/{$id}/approve"], ['POST', self::API."/{$id}/retry"]];

        $this->signIn('manager');
        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        }
        app('auth')->forgetGuards();
        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public static function types(): array
    {
        return array_combine($types = ['experience_certificate', 'leave_request', 'advance_request', 'overtime_request'], array_map(fn ($t) => [$t], $types));
    }

    #[DataProvider('types')]
    public function test_create_replays_supersedes_and_audits_the_fields_not_the_snapshot(string $type): void
    {
        $this->signIn('hr');
        [$subject, $fields] = $this->subject($type);
        $key = (string) Str::uuid7();
        $first = $this->create($type, $subject, $fields, $key)->assertAccepted()
            ->assertJsonPath('data.type', $type)->assertJsonPath('data.serial_number', 1)->assertJsonPath('data.stage', 'unsigned')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.regenerated_from_id', null)->assertJsonPath('data.actions', [])
            ->assertJsonPath('data.file_name', str_replace('_', '-', $type).'-1-unsigned.pdf')
            ->assertJsonMissingPath('data.snapshot')->assertJsonMissingPath('data.form_type')->json('data.id');
        $this->create($type, $subject, $fields, $key)->assertAccepted()->assertJsonPath('data.id', $first);
        $this->create($type, $subject, [...$fields, 'job_title' => 'Another title'], $key)
            ->assertConflict()->assertJsonPath('error.code', 'concurrency_conflict');
        $second = $this->create($type, $subject, $fields)->assertAccepted()
            ->assertJsonPath('data.serial_number', 2)->assertJsonPath('data.regenerated_from_id', $first)->json('data.id');

        Queue::assertPushedOn('pdf', RenderPdf::class, fn (RenderPdf $job) => $job->exportId === $second);
        $audit = fn (string $id): string => (string) DB::table('audit_log')->where(['event' => 'CREATED', 'entity_type' => 'hr_form_exports', 'entity_id' => $id])->sole()->new_values;
        $this->assertEquals(['form_type' => $type, 'serial_number' => 1, 'stage' => 'unsigned', 'regenerated_from_id' => null, 'fields' => $fields],
            json_decode($audit($first), true));
        $this->assertEquals(['form_type' => $type, 'serial_number' => 2, 'stage' => 'unsigned', 'regenerated_from_id' => $first, 'fields' => $fields],
            json_decode($audit($second), true));
        $this->assertStringNotContainsString('salary', $audit($second));
        $this->assertStringNotContainsString('8765', $audit($second));
    }

    public function test_a_replay_answers_before_the_subject_is_read_again(): void
    {
        $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $key = (string) Str::uuid7();
        $id = $this->create('experience_certificate', $employee, $fields, $key)->assertAccepted()->json('data.id');
        DB::table('employees')->where('id', $employee)->update(['national_id' => null]);

        $this->create('experience_certificate', $employee, $fields, $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->create('experience_certificate', $employee, $fields)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->create('advance_request', $employee, [...$fields, 'reason' => 'Synthetic reason', 'repayment_method' => 'cash', 'previous_advance' => false], $key)
            ->assertConflict();
    }

    public function test_a_certificate_is_issued_for_an_archived_employee(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01', ['national_id' => '12345678901234', 'department' => 'QA', 'termination_date' => '2026-09-30']);

        $this->create('experience_certificate', $employee, ['job_title' => 'Synthetic QA'])->assertAccepted()->assertJsonPath('data.employee_id', $employee);
    }

    public function test_create_validates_the_type_subject_and_fields(): void
    {
        $this->signIn('hr');
        [$request, $fields] = $this->subject('advance_request');

        $this->create('end_of_service', $request, $fields)->assertUnprocessable()->assertJsonPath('error.details.0.field', 'type');
        $this->create('advance_request', $request, [...$fields, 'paper_decision_date' => '2026-10-20'], headers: ['Accept-Language' => 'en'])
            ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'fields.paper_decision_date')
            ->assertJsonPath('error.details.0.message', __('errors.advance_paper_date_pending', [], 'en'));
        $this->create('advance_request', $request, [...$fields, 'job_title' => null])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'fields.job_title');
        $this->create('overtime_request', $request, $fields)->assertNotFound();
        $this->create('leave_request', (string) Str::uuid7(), $fields)->assertNotFound();
        $id = $this->create('advance_request', $request, [...$fields, 'finance_remarks' => null, 'extra' => 'dropped'])->assertAccepted()->json('data.id');
        $snapshot = json_decode((string) DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true);
        $this->assertSame(['', false, true], [$snapshot['finance_remarks'], $snapshot['previous_advance'], $snapshot['shows_pay']]);
        $this->assertArrayNotHasKey('extra', $snapshot);
    }

    public function test_advance_rows_are_hidden_from_a_caller_without_pay(): void
    {
        $this->signIn('hr');
        [$request, $fields] = $this->subject('advance_request');
        $advance = $this->create('advance_request', $request, $fields)->json('data.id');
        $this->settle($advance);
        [$employee, $certificateFields] = $this->subject('experience_certificate');
        $certificate = $this->create('experience_certificate', $employee, $certificateFields)->json('data.id');
        $this->settle($certificate);

        $this->actingAs($this->holder('hr_forms.view.all', 'hr_forms.generate.all'), 'api');
        $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $certificate);
        $this->getJson(self::API."?type=advance_request&subject_id={$request}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/{$advance}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get(self::API."/{$advance}/download")->assertNotFound();
        $this->postJson(self::API."/{$advance}/retry")->assertNotFound();
        $this->create('advance_request', $request, $fields)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->get(self::API."/{$certificate}/download")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('experience-certificate-1-unsigned.pdf');
    }

    public function test_own_scope_reaches_only_the_advance_of_employees_in_reach(): void
    {
        $this->signIn('hr');
        $this->site = $this->workSite();
        $inReach = $this->employee('permanent_worker', '2026-01-01', ['salary' => self::SALARY]);
        $this->homeSite($inReach, $this->site, '2026-01-01');
        $outOfReach = $this->employee('permanent_worker', '2026-01-01', ['salary' => self::SALARY]);
        [, $fields] = $this->subject('advance_request');
        [, $overtimeFields] = $this->subject('overtime_request');
        $mine = $this->attendanceRequest('advance', $inReach);
        $theirs = $this->attendanceRequest('advance', $outOfReach);
        $overtimeRequest = $this->attendanceRequest('overtime', $inReach);
        $hidden = $this->create('advance_request', $theirs, $fields)->json('data.id');
        $overtime = $this->create('overtime_request', $overtimeRequest, $overtimeFields)->json('data.id');
        [$employee, $certificateFields] = $this->subject('experience_certificate');
        $certificate = $this->create('experience_certificate', $employee, $certificateFields)->json('data.id');

        $manager = $this->holder('hr_forms.generate.own', 'hr_forms.view.own', 'employee.view.all');
        DB::table('manager_sites')->insert(['manager_id' => $manager->id, 'site_id' => $this->site]);
        $this->actingAs($manager, 'api');
        $this->create('experience_certificate', $employee, $certificateFields)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->create('overtime_request', $overtimeRequest, $overtimeFields)->assertForbidden();
        $this->create('advance_request', $theirs, $fields)->assertNotFound();
        $own = $this->create('advance_request', $mine, $fields)->assertAccepted()->json('data.id');

        $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        foreach ([$hidden, $overtime, $certificate] as $id) {
            $this->getJson(self::API."/{$id}")->assertNotFound();
        }
        $this->getJson(self::API."/{$own}")->assertOk();
    }

    public function test_approve_follows_the_type_permission_and_the_signer_rule(): void
    {
        $hr = $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $certificate = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $this->settle($certificate);
        [$request, $advanceFields] = $this->subject('advance_request');
        $advance = $this->create('advance_request', $request, $advanceFields)->json('data.id');
        $this->settle($advance);
        $leave = $this->approvedLeavePdf();
        $this->signature($hr->id);

        $this->postJson(self::API."/{$certificate}/approve")->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $ceo = $this->signIn('ceo');
        $this->signature($ceo->id);
        $this->postJson(self::API."/{$advance}/approve")->assertConflict();
        $this->postJson(self::API."/{$leave}/approve")->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $signed = $this->postJson(self::API."/{$certificate}/approve")->assertAccepted()
            ->assertJsonPath('data.stage', 'signed')->assertJsonPath('data.serial_number', 1)->assertJsonPath('data.unsigned_export_id', $certificate)
            ->assertJsonPath('data.approved_by', $ceo->id)->assertJsonPath('data.file_name', 'experience-certificate-1-signed.pdf')->json('data.id');
        $this->postJson(self::API."/{$certificate}/approve")->assertAccepted()->assertJsonPath('data.id', $signed);
        $this->assertSame(1, DB::table('audit_log')->where(['event' => 'APPROVED', 'entity_id' => $signed])->count());
        Queue::assertPushedOn('pdf', RenderPdf::class, fn (RenderPdf $job) => $job->exportId === $signed);

        $this->actingAs($hr, 'api');
        $this->postJson(self::API."/{$leave}/approve")->assertAccepted()->assertJsonPath('data.stage', 'signed');
    }

    public function test_view_as_cannot_approve(): void
    {
        $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $certificate = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $this->settle($certificate);
        $ceo = $this->makeUser('ceo');
        $this->signature($ceo->id);
        $developer = $this->makeUser('developer');
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $developer->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        $view = $this->withToken($token)->postJson('/api/v1/auth/view-as', ['user_id' => $ceo->id, 'reason' => 'Synthetic QA'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();
        $this->withToken($view)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $ceo->id)->assertJsonPath('data.view_as.by.id', $developer->id);
        app('auth')->forgetGuards();

        $this->withToken($view)->postJson(self::API."/{$certificate}/approve")->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->assertDatabaseMissing('hr_form_exports', ['stage' => 'signed']);
    }

    public function test_retry_takes_failed_rows_only_and_a_signed_row_from_its_approver(): void
    {
        $hr = $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $certificate = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $this->settle($certificate, 'failed');
        $this->postJson(self::API."/{$certificate}/retry")->assertAccepted()->assertJsonPath('data.id', $certificate)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.failure_code', null);
        Queue::assertPushedOn('pdf', RenderPdf::class, fn (RenderPdf $job) => $job->exportId === $certificate);
        $this->postJson(self::API."/{$certificate}/retry")->assertConflict();
        $this->settle($certificate);
        $this->postJson(self::API."/{$certificate}/retry")->assertConflict();

        $leave = $this->approvedLeavePdf();
        $this->signature($hr->id);
        $signed = $this->postJson(self::API."/{$leave}/approve")->assertAccepted()->json('data.id');
        $this->settle($signed, 'failed');
        $this->settle($leave, 'failed');
        $this->signIn('ceo');
        $this->postJson(self::API."/{$signed}/retry")->assertConflict();
        $this->postJson(self::API."/{$leave}/retry")->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->actingAs($hr, 'api');
        $this->postJson(self::API."/{$signed}/retry")->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, DB::table('audit_log')->where('event', 'like', 'RETR%')->count());
    }

    public function test_actions_are_computed_for_the_caller(): void
    {
        $hr = $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $ready = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $this->settle($ready);
        [$other, $fields] = $this->subject('experience_certificate');
        $failed = $this->create('experience_certificate', $other, $fields)->json('data.id');
        $this->settle($failed, 'failed');
        $leave = $this->approvedLeavePdf();
        $actions = fn (): array => collect($this->getJson(self::API)->assertOk()->json('data'))->pluck('actions', 'id')->all();

        $this->assertEquals([$ready => ['download'], $failed => ['retry'], $leave => ['download', 'approve']], $actions());
        $this->getJson(self::API."/{$leave}")->assertOk()->assertJsonPath('data.actions', ['download', 'approve']);
        $this->signIn('ceo');
        $this->assertEquals([$ready => ['download', 'approve'], $failed => [], $leave => ['download']], $actions());
        $this->actingAs($this->holder('hr_forms.view.all', 'employee.view.all'), 'api');
        $this->assertEquals([$ready => ['download'], $failed => [], $leave => ['download']], $actions());
        $this->actingAs($hr, 'api');
        $this->signature($hr->id);
        $this->postJson(self::API."/{$leave}/approve")->assertAccepted();
        $this->getJson(self::API."/{$leave}")->assertOk()->assertJsonPath('data.actions', ['download']);
    }

    public function test_list_filters_order_and_pages(): void
    {
        $this->signIn('hr');
        [$employee, $fields] = $this->subject('experience_certificate');
        $older = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $newer = $this->create('experience_certificate', $employee, $fields)->json('data.id');
        $searched = $this->employee('office', '2022-01-01', ['name_en' => 'Searchable Synthetic', 'national_id' => '12345678901235', 'department' => 'QA']);
        $found = $this->create('experience_certificate', $searched, $fields)->json('data.id');
        $leave = $this->approvedLeavePdf();
        $this->settle($older);
        $this->settle($newer);
        $this->settle($found);
        $ids = fn (string $query): array => array_column($this->getJson(self::API.$query)->assertOk()->json('data'), 'id');

        $this->assertSame([$newer, $older], $ids("?type=experience_certificate&subject_id={$employee}"));
        $this->assertSame([$newer, $older], $ids("?employee_id={$employee}"));
        $this->assertSame([$found], $ids('?q=searchable'));
        $this->assertContains($newer, $ids('?q=2'));
        $this->assertSame([$leave], $ids('?awaiting_approval=1'));
        $this->assertSame([$found, $newer, $older], $ids('?type=experience_certificate&stage=unsigned&status=ready'));
        $this->assertSame([], $ids('?stage=signed'));
        $this->getJson(self::API.'?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 1, 'total' => 4, 'total_pages' => 4, 'has_next_page' => true, 'has_previous_page' => true]);
        foreach (["?subject_id={$employee}", '?type=end_of_service', '?per_page=101', '?page=0', '?stage=draft', '?employee_id=1', '?q='.str_repeat('x', 101)] as $query) {
            $this->getJson(self::API.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->signIn('ceo');
        $this->assertSame([$found, $newer, $leave], $ids('?awaiting_approval=1'));
    }

    public function test_rows_of_deleted_types_are_absent_and_not_found(): void
    {
        $hr = $this->signIn('hr');
        $employee = $this->employee('office', '2022-01-01');
        $id = (string) Str::uuid7();
        DB::table('hr_form_exports')->insert([
            'id' => $id, 'form_type' => 'end_of_service', 'serial_number' => 1, 'stage' => 'unsigned', 'status' => 'failed',
            'employee_id' => $employee, 'requested_by' => $hr->id, 'idempotency_key' => (string) Str::uuid7(),
            'template_version' => 1, 'snapshot' => json_encode(['final_amount' => '6172.835000']), 'failure_code' => 'render_failed',
            'storage_path' => "hr-forms/{$id}.pdf", 'created_by' => $hr->id, 'updated_by' => $hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson(self::API)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."?employee_id={$employee}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/{$id}")->assertNotFound();
        $this->get(self::API."/{$id}/download")->assertNotFound();
        $this->postJson(self::API."/{$id}/retry")->assertNotFound();
        $this->signIn('ceo');
        $this->postJson(self::API."/{$id}/approve")->assertNotFound();
    }

    /** @return array{0: string, 1: array<string, mixed>} [subject_id, fields], made by the signed-in HR user */
    private function subject(string $type): array
    {
        return match ($type) {
            'experience_certificate' => [$this->employee('office', '2022-01-01', ['national_id' => sprintf('2990101%07d', ++$this->nationalIds), 'department' => 'QA']), ['job_title' => 'Synthetic QA']],
            'leave_request' => [$this->leave(), ['job_title' => 'Synthetic QA', 'notification_method' => 'Phone']],
            'advance_request' => [$this->attendanceRequest('advance'), ['job_title' => 'Synthetic QA', 'reason' => 'Synthetic reason', 'repayment_method' => 'cash', 'previous_advance' => false]],
            'overtime_request' => [$this->attendanceRequest('overtime'), ['job_title' => 'Synthetic QA', 'reason' => 'Synthetic reason']],
        };
    }

    private function create(string $type, string $subject, array $fields, ?string $key = null, array $headers = []): TestResponse
    {
        return $this->postJson(self::API, ['type' => $type, 'subject_id' => $subject, 'idempotency_key' => $key ?? (string) Str::uuid7(), 'fields' => $fields], $headers);
    }

    private function attendanceRequest(string $kind, ?string $employee = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('attendance_requests')->insert([
            'id' => $id, 'employee_id' => $employee ?? $this->employee('permanent_worker', '2026-01-01', ['salary' => self::SALARY]),
            'site_id' => $this->site ??= $this->workSite(), 'type' => $kind, 'date' => '2026-10-20', 'requested_by' => auth()->id(),
            ...($kind === 'overtime' ? ['minutes' => 60, 'in_time' => '09:00', 'out_time' => '17:35'] : ['amount' => '250']),
        ]);

        return $id;
    }

    private function leave(): string
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $this->employee('office', '2020-01-01'), 'kind' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'note' => 'Synthetic reason'])->assertCreated()->json('data.id');
    }

    /** A ready unsigned leave PDF of a request the signed-in user approved. */
    private function approvedLeavePdf(): string
    {
        $request = $this->leave();
        $this->postJson("/api/v1/leave/requests/{$request}/approve", ['version' => (int) DB::table('leave_requests')->where('id', $request)->value('version')])->assertOk();
        $id = $this->create('leave_request', $request, ['job_title' => 'Synthetic QA'])->assertAccepted()->json('data.id');
        $this->settle($id);

        return $id;
    }

    /** What the render job leaves: a stored file and the status. */
    private function settle(string $id, string $status = 'ready'): void
    {
        $ready = $status === 'ready';
        DB::table('hr_form_exports')->where('id', $id)->update([
            'status' => $status, 'sha256' => $ready ? str_repeat('a', 64) : null, 'size_bytes' => $ready ? 8 : null, 'failure_code' => $ready ? null : 'render_failed',
        ]);
        Storage::disk('local')->put("hr-forms/{$id}.pdf", '%PDF-1.7');
    }

    private function signature(string $owner): void
    {
        $id = (string) Str::uuid7();
        DB::table('hr_signature_images')->insert([
            'id' => $id, 'owner_id' => $owner, 'version' => 1, 'storage_path' => "hr-signatures/{$id}.png",
            'mime_type' => 'image/png', 'checksum' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A user whose role holds exactly these `resource.action.scope` grants. */
    private function holder(string ...$triples): User
    {
        $role = Role::create(['slug' => 'synthetic-'.Str::lower(Str::random(6)), 'name' => 'Synthetic', 'name_ar' => 'اختبار']);
        foreach ($triples as $triple) {
            [$resource, $action, $scope] = explode('.', $triple);
            $role->permissions()->attach(Permission::firstOrCreate(compact('resource', 'action', 'scope'))->getKey());
        }

        return $this->makeUser($role->slug);
    }
}
