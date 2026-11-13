<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceRequestTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const DATE = '2026-10-22';

    private string $site;

    private string $worker;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo(self::DATE.' 19:00');
        $this->signIn('hr');
        $this->site = $this->workSite('Site-1');
        $this->manager = $this->managerOf($this->site);
        $this->homeSite($this->worker = $this->employee('permanent_worker'), $this->site, '2026-10-01');
        DB::table('settings')->where('key', 'worker_shift_start')->update(['value' => '09:00']);
        DB::table('settings')->where('key', 'worker_shift_end')->update(['value' => '16:00']);
        BusinessRules::forgetCache();
    }

    public function test_overtime_begins_at_shift_end_and_approval_makes_the_day_hrs(): void
    {
        $this->worker = $this->employee('engineer');
        app(EmployeeService::class)->assignManager($this->worker, ['manager_id' => $this->manager->id, 'effective_from' => '2026-10-01']);
        $saved = $this->save('17:00')->assertOk()
            ->assertJsonPath('data.rows.1.requests.overtime.minutes', 60)
            ->assertJsonPath('data.rows.1.requests.overtime.status', 'pending');
        $this->assertSame(0, $this->day()->ot_minutes);
        $id = $saved->json('data.rows.1.requests.overtime.id');
        $this->getJson('/api/v1/attendance/requests')->assertForbidden();
        $this->actingAs($this->makeUser('ceo'), 'api')->getJson('/api/v1/attendance/requests')->assertForbidden();
        $this->save('16:30', 1)->assertOk()
            ->assertJsonPath('data.rows.1.requests.overtime.minutes', 30)
            ->assertJsonPath('data.rows.1.requests.overtime.status', 'pending');
        $this->assertSame(0, $this->day()->ot_minutes);
        $this->signIn('hr');
        $this->approve($id, 1)->assertConflict();
        $this->approve($id, 2)->assertOk();
        $this->assertSame(30, $this->day()->ot_minutes);
        $this->assertSame('entered', $this->day()->source);
        $this->save('17:00', 3)->assertUnprocessable();
        $this->assertSame(30, $this->day()->ot_minutes);
    }

    public function test_an_advance_is_recorded_as_paid_once_on_paper_approval(): void
    {
        $saved = $this->save(null, null, ['advance_request' => '250.5'])->assertOk()
            ->assertJsonPath('data.rows.0.requests.advance.status', 'pending');
        $id = $saved->json('data.rows.0.requests.advance.id');
        $this->assertNull($this->day()->advance_amount);

        $this->signIn('hr');
        $this->getJson('/api/v1/attendance/requests?type=advance')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.amount', '250.500000');
        $this->approve($id, 1)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('250.500000', $this->day()->advance_amount);
        $this->approve($id, 1)->assertConflict();
        $this->save('16:00', 2, ['advance_request' => '400'])->assertConflict();
        $this->assertNull($this->day()->out_time);
        $this->save('16:00', 2)->assertOk()->assertJsonPath('data.rows.0.requests.advance.status', 'approved');
        $this->assertSame('250.500000', $this->day()->advance_amount);
    }

    public function test_the_site_manager_cannot_clear_or_move_a_day_holding_an_approved_advance(): void
    {
        $leave = $this->leaveTypes();
        $id = $this->save('16:00', null, ['advance_request' => '250'])->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $this->approve($id, 1)->assertOk();

        $this->actingAs($this->manager, 'api');
        foreach ([['clear' => true], ['site_id' => $leave['absent']]] as $change) {
            $this->postJson('/api/v1/attendance/site-day', [
                'site_id' => $this->site, 'date' => self::DATE,
                'rows' => [['employee_id' => $this->worker, 'version' => 2, ...$change]],
            ])->assertStatus(422)->assertJsonPath('error.code', 'hr_day');
        }
        $this->assertSame([$this->site, '250.000000'], [$this->day()->site_id, $this->day()->advance_amount]);
        $this->assertNull($this->day()->deleted_at);
    }

    public function test_a_revised_advance_makes_the_printed_requests_previous_revision_stale(): void
    {
        $saved = $this->save(null, null, ['advance_request' => '200'])->assertOk();
        $id = $saved->json('data.rows.0.requests.advance.id');
        $this->save(null, 1, ['advance_request' => '250'])->assertOk();
        $this->save(null, 1, ['advance_request' => '300'])->assertConflict();
        $this->signIn('hr');
        $this->approve($id, 1)->assertConflict();
        $this->assertNull($this->day()->advance_amount);
    }

    public function test_an_attendance_change_by_another_manager_preserves_the_pending_advance(): void
    {
        $this->save('16:00', null, ['advance_request' => '250'])->assertOk();
        $other = $this->managerOf($this->site);
        $this->actingAs($other, 'api')->postJson('/api/v1/attendance/site-day', [
            'site_id' => $this->site, 'date' => self::DATE,
            'rows' => [['employee_id' => $this->worker, 'version' => 1, 'clear' => true]],
        ])->assertOk();
        $this->assertDatabaseHas('attendance_requests', [
            'employee_id' => $this->worker, 'type' => 'advance', 'requested_by' => $this->manager->id,
            'status' => 'pending', 'deleted_at' => null,
        ]);
    }

    public function test_a_locked_period_blocks_both_decisions(): void
    {
        $this->leaveTypes();
        $id = $this->save('16:00', null, ['advance_request' => '250'])->assertOk()->json('data.rows.0.requests.advance.id');
        $this->signIn('hr');
        $this->postJson('/api/v1/attendance/locks', [
            'group' => 'permanent_worker', 'period_start' => '2026-09-26', 'confirm_missing' => true,
        ])->assertCreated();
        $this->approve($id, 1)->assertStatus(423);
        $this->postJson("/api/v1/attendance/requests/{$id}/refuse", ['version' => 1, 'reason' => 'Test'])->assertStatus(423);
        $this->assertDatabaseHas('attendance_requests', ['id' => $id, 'status' => 'pending', 'version' => 1]);
    }

    /** @param array<string, mixed> $extra */
    private function save(?string $out, ?int $version = null, array $extra = []): TestResponse
    {
        return $this->actingAs($this->manager, 'api')->postJson('/api/v1/attendance/site-day', [
            'site_id' => $this->site, 'date' => self::DATE,
            'rows' => [['employee_id' => $this->worker, 'site_id' => $this->site, 'in_time' => '09:00', 'out_time' => $out, 'version' => $version, ...$extra]],
        ]);
    }

    private function approve(string $id, int $version): TestResponse
    {
        return $this->postJson("/api/v1/attendance/requests/{$id}/approve", ['version' => $version]);
    }

    private function day(): object
    {
        return DB::table('attendance_days')->where(['date' => self::DATE, 'employee_id' => $this->worker])->firstOrFail();
    }
}
