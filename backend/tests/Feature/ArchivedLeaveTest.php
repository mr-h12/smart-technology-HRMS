<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Leave for an archived employee (owner, 2026-11-11): no new request; the requests list keeps only their open ones until decided. */
class ArchivedLeaveTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leaveTypes();
    }

    private function submit(string $employeeId, string $from, string $to): TestResponse
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $employeeId, 'kind' => 'annual', 'from_date' => $from, 'to_date' => $to]);
    }

    private function approve(string $id): TestResponse
    {
        return $this->postJson("/api/v1/leave/requests/{$id}/approve", ['version' => DB::table('leave_requests')->where('id', $id)->value('version')]);
    }

    /** @return list<string> */
    private function listed(string $query = ''): array
    {
        return $this->getJson('/api/v1/leave/requests'.$query)->assertOk()->json('data.*.id');
    }

    public function test_no_new_request_can_be_previewed_or_filed_for_an_archived_employee(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-19']);

        $this->getJson("/api/v1/leave/requests/preview?employee_id={$e}&kind=annual&from_date=2026-10-12&to_date=2026-10-13")
            ->assertStatus(409)->assertJsonPath('error.code', 'employee_archived');
        $this->submit($e, '2026-10-12', '2026-10-13')->assertStatus(409)->assertJsonPath('error.code', 'employee_archived');
        $this->assertSame(0, DB::table('leave_requests')->count());
    }

    public function test_the_list_hides_an_archived_employees_decided_requests_but_keeps_the_open_ones(): void
    {
        $e = $this->employee('engineer', '2026-01-01', ['termination_date' => '2026-10-25']);
        $a = $this->submit($e, '2026-10-21', '2026-10-22')->assertCreated()->json('data.id');
        $this->approve($a)->assertOk();
        $b = $this->submit($e, '2026-10-24', '2026-10-24')->assertCreated()->json('data.id');
        $this->cairo('2026-10-26 12:00');                                                           // now archived

        $this->assertSame([$b], $this->listed());
        $this->assertEqualsCanonicalizing([$a, $b], $this->listed("?employee_id={$e}"));         // their Leave tab keeps the history
        $this->approve($b)->assertOk();
        $this->assertSame([], $this->listed());
    }
}
