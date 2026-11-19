<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class RefusedLaborerEdgeTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private User $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        $this->s1 = $this->workSite('Site-1');
        $this->m = $this->managerOf($this->s1);
    }

    /** @return array{id: string, proposal_id: string} */
    private function proposeOne(): array
    {
        $this->actingAs($this->m, 'api');

        return $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد أول', 'gender' => 'male', 'daily_wage' => '350'])->assertCreated()->json('data');
    }

    public function test_after_a_refusal_the_site_manager_records_no_new_day_in_the_yesterday_window(): void
    {
        $made = $this->proposeOne();                                                                    // on 10-22's crew, nothing recorded
        $this->cairo('2026-10-23 09:00');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse", ['version' => 1, 'reason' => 'لا يوجد احتياج'])->assertOk();

        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-23 10:00');                                                               // still before noon: 10-22 is open to the manager
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $made['id'], 'site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00']]])
            ->assertNotFound();   // off the 10-22 list: nothing was recorded that day
        $this->assertSame(0, DB::table('attendance_days')->where('employee_id', $made['id'])->count());
    }

    public function test_a_refusal_never_moves_an_earlier_termination_date_later(): void
    {
        $made = $this->proposeOne();
        DB::table('employees')->where('id', $made['id'])->update(['termination_date' => '2026-10-22']);   // HR ended them on the record first
        $this->cairo('2026-10-24 09:00');
        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$made['proposal_id']}/refuse", ['version' => 1, 'reason' => 'لا يوجد احتياج'])->assertOk();
        $this->assertSame('2026-10-22', (string) DB::table('employees')->where('id', $made['id'])->value('termination_date'));
    }
}
