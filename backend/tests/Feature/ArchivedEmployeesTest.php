<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** An archived employee (termination date before today in Cairo): the shared rule, and what their record accepts. */
class ArchivedEmployeesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('hr');
    }

    public function test_the_archive_starts_the_day_after_the_termination_date_in_cairo(): void
    {
        $this->travelTo('2026-10-20 22:30:00');                                     // 2026-10-21 01:30 in Cairo (summer time, UTC+3)
        $left = $this->employee('office', '2026-01-01', ['termination_date' => '2026-10-20']);
        $lastDay = $this->employee('office', '2026-01-01', ['termination_date' => '2026-10-21']);   // the termination day is still worked
        $staying = $this->employee('office');

        $this->assertTrue(Employee::isArchived('2026-10-20'));
        $this->assertFalse(Employee::isArchived('2026-10-21'));
        $this->assertFalse(Employee::isArchived(null));
        $this->assertSame([$left], Employee::query()->archived()->pluck('id')->all());
        $this->assertSame([$lastDay, $staying], Employee::query()->archived(false)->orderBy('code')->pluck('id')->all());
        $this->getJson("/api/v1/employees/{$left}")->assertOk()->assertJsonPath('data.archived', true);
        $this->getJson("/api/v1/employees/{$lastDay}")->assertOk()->assertJsonPath('data.archived', false);
    }
}
