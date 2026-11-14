<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AttendanceFixtures;
use Tests\TestCase;

final class HrOvertimeRequestFormTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

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
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'department' => 'Synthetic QA',
        ]);
        $this->homeSite($this->employeeId, $this->site, '2026-01-01');
    }

    public static function requests(): array
    {
        return [
            ['pending', 60, 'ساعة واحدة'],
            ['approved', 30, '30 دقيقة'],
            ['refused', 95, 'ساعة واحدة و35 دقيقة'],
        ];
    }

    #[DataProvider('requests')]
    public function test_snapshot_and_render_across_request_statuses(string $status, int $minutes, string $duration): void
    {
        $id = $this->attendanceRequest($status, $minutes);
        $source = (array) DB::table('attendance_requests')->where('id', $id)->first();
        $export = $this->generate($id)->assertAccepted()->assertJsonPath('data.form_type', 'overtime_request')->json('data.id');
        $snapshot = json_decode(DB::table('hr_form_exports')->where('id', $export)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals([
            'name_ar' => 'سارة مثال للاختبار', 'name_en' => 'Synthetic Worker', 'department' => 'Synthetic QA', 'code' => 1, 'gender' => 'male',
            'job_title' => 'Synthetic technician', 'reason' => 'Synthetic overtime reason',
            'overtime_request_id' => $id, 'request_date' => '2026-10-22', 'checkout_time' => '17:35:00', 'minutes' => $minutes,
        ], $snapshot);
        $this->assertSame($source, (array) DB::table('attendance_requests')->where('id', $id)->first());
        $this->assertDatabaseCount('attendance_days', 0);
        DB::table('attendance_requests')->where('id', $id)->update(['minutes' => 120, 'out_time' => '18:00:00']);
        $this->render($export, $duration);
    }

    public function test_permissions_wrong_request_type_and_refused_approval(): void
    {
        $id = $this->attendanceRequest();
        $export = $this->generate($id)->assertAccepted()->json('data.id');
        $this->render($export);
        $this->generate($this->attendanceRequest(type: 'advance'))->assertNotFound();

        $this->signIn('developer');
        $this->generate($id)->assertAccepted();
        $this->assertReadable($id, $export);
        $this->signIn('ceo');
        $this->generate($id)->assertForbidden();
        $this->assertReadable($id, $export);
        $this->postJson("/api/v1/hr-form-exports/{$export}/approve")->assertConflict();
        $this->assertDatabaseMissing('hr_form_exports', ['form_type' => 'overtime_request', 'stage' => 'signed']);

        $role = Role::create(['slug' => 'employee', 'name' => 'Employee', 'name_ar' => 'موظف']);
        foreach ([$this->managerOf($this->site), $this->makeUser('hr', ['role_id' => $role->id])] as $user) {
            $this->actingAs($user, 'api');
            $this->generate($id)->assertForbidden();
            $this->getJson("/api/v1/attendance/requests/{$id}/hr-form-exports")->assertForbidden();
            $this->getJson("/api/v1/hr-form-exports/{$export}")->assertForbidden();
            $this->get("/api/v1/hr-form-exports/{$export}/download")->assertForbidden();
        }
    }

    public function test_database_requires_positive_overtime_and_a_checkout(): void
    {
        $id = $this->attendanceRequest();
        foreach ([['minutes' => 0], ['minutes' => null], ['out_time' => null]] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('attendance_requests')->where('id', $id)->update($invalid));
                $this->fail('The attendance request check accepted invalid overtime.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
    }

    public function test_history_for_an_unsupported_request_type_is_empty(): void
    {
        $id = $this->attendanceRequest();
        // Simulate a future request type in this test's rolled-back transaction.
        $export = $this->generate($id)->assertAccepted()->json('data.id');
        DB::table('hr_form_exports')->where('id', $export)->update([
            'form_type' => 'other_request', 'snapshot' => json_encode(['other_request_id' => $id], JSON_THROW_ON_ERROR),
        ]);
        DB::statement('alter table attendance_requests drop constraint attendance_requests_check');
        DB::table('attendance_requests')->where('id', $id)->update(['type' => 'other']);
        $this->getJson("/api/v1/attendance/requests/{$id}/hr-form-exports")
            ->assertOk()->assertJsonPath('data.exports', []);
    }

    public function test_validation_idempotency_and_unsigned_retry(): void
    {
        $id = $this->attendanceRequest();
        foreach (['job_title' => 160, 'reason' => 300] as $field => $limit) {
            foreach ([null, '   ', str_repeat('x', $limit + 1)] as $invalid) {
                $this->generate($id, [$field => $invalid])->assertUnprocessable()->assertJsonPath('error.details.0.field', $field);
            }
        }
        $key = (string) Str::uuid7();
        $export = $this->generate($id, key: $key)->assertAccepted()->assertJsonPath('data.serial_number', 1)->json('data.id');
        $this->generate($id, key: $key)->assertAccepted()->assertJsonPath('data.id', $export);
        $this->generate($id, ['reason' => 'A different reason'], $key)->assertConflict();
        (new RenderPdf($export))->failed(null);
        $path = "/api/v1/hr-form-exports/{$export}/retry-overtime-request";
        $this->postJson($path)->assertAccepted()->assertJsonPath('data.id', $export);
        $this->postJson($path)->assertConflict();
        $this->render($export);
        $this->assertDatabaseCount('hr_form_exports', 1);
        $this->generate($id)->assertAccepted()->assertJsonPath('data.serial_number', 2);
    }

    private function attendanceRequest(string $status = 'pending', int $minutes = 60, string $type = 'overtime'): string
    {
        $id = (string) Str::uuid7();
        DB::table('attendance_requests')->insert([
            'id' => $id, 'employee_id' => $this->employeeId, 'site_id' => $this->site, 'type' => $type,
            'date' => '2026-10-22', 'requested_by' => auth()->id(), 'status' => $status, 'reason' => 'Decision reason must stay private to this form',
            ...($type === 'overtime' ? ['minutes' => $minutes, 'in_time' => '09:00', 'out_time' => '17:35'] : ['amount' => '250']),
        ]);

        return $id;
    }

    private function generate(string $id, array $fields = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/attendance/requests/{$id}/hr-forms/overtime-request", [
            'idempotency_key' => $key ?? (string) Str::uuid7(), 'job_title' => '  Synthetic technician  ',
            'reason' => '  Synthetic overtime reason  ', ...$fields,
        ]);
    }

    private function assertReadable(string $id, string $export): void
    {
        $this->getJson("/api/v1/attendance/requests/{$id}/hr-form-exports")->assertOk();
        $this->getJson("/api/v1/hr-form-exports/{$export}")->assertOk();
        $this->get("/api/v1/hr-form-exports/{$export}/download")->assertOk();
    }

    private function render(string $export, string $duration = 'ساعة واحدة'): void
    {
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->with($this->callback(fn (string $html): bool => str_contains($html, $duration)
            && str_contains($html, 'الخميس الموافق <bdi>22/10/2026</bdi>') && str_contains($html, '<bdi>17:35</bdi>')
            && str_contains($html, 'Synthetic overtime reason') && substr_count($html, 'class="hand"') === 3
            && substr_count($html, '____ / ____ / ____') === 3
            && ! str_contains($html, '<img') && ! str_contains($html, 'Decision reason')))->willReturn('%PDF-1.7 overtime');
        (new RenderPdf($export))->handle($renderer);
    }
}
