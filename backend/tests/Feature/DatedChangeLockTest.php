<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** M1 (docs/reviews/2026-11-08-release-1-review.md): a dated change that would alter a locked attendance period is refused. */
class DatedChangeLockTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const LOCKED = 'dated_change_period_locked';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-08-10 10:00');
        $this->signIn('hr');
        $this->leaveTypes();
    }

    private function lock(string $group, string $start = '2026-06-26'): void
    {
        $this->postJson('/api/v1/attendance/locks', ['group' => $group, 'period_start' => $start, 'confirm_missing' => true])->assertCreated();
    }

    private function assertRefused(TestResponse $r, string $key, string $field = 'effective_from'): void
    {
        $r->assertStatus(422)->assertJsonPath('error.details.0.field', $field)->assertJsonPath('error.details.0.message', __("errors.{$key}"));
    }

    public function test_type_change_inside_a_locked_period_is_refused(): void
    {
        $eng = $this->employee('engineer');
        $office = $this->employee('office');
        $this->lock('engineer');
        $this->lock('day_laborer', '2026-07-16');

        // The old type's lock: 07-01 would move the engineer's locked days into the open day-laborer period 07-01..15.
        $this->assertRefused($this->postJson("/api/v1/employees/{$eng}/type-changes", ['type' => 'day_laborer', 'effective_from' => '2026-07-01']), self::LOCKED);
        $this->putDay($eng, '2026-07-12', ['site_id' => $this->workSite()])->assertStatus(423);
        // The new type's lock: no office period is locked, the day-laborer period 07-16..31 is.
        $this->assertRefused($this->postJson("/api/v1/employees/{$office}/type-changes", ['type' => 'day_laborer', 'effective_from' => '2026-07-16']), self::LOCKED);
    }

    public function test_type_change_must_start_a_period_of_the_new_type(): void
    {
        $office = $this->employee('office');
        $eng = $this->employee('engineer');

        // Engineer periods start on the 26th.
        $this->assertRefused($this->postJson("/api/v1/employees/{$office}/type-changes", ['type' => 'engineer', 'effective_from' => '2026-07-16']), 'type_change_not_period_start');
        // The new type's period start is enough (spec open point, default): the old type's last period ends the day before.
        $this->postJson("/api/v1/employees/{$office}/type-changes", ['type' => 'day_laborer', 'effective_from' => '2026-07-16'])->assertCreated();
        $this->postJson("/api/v1/employees/{$eng}/type-changes", ['type' => 'day_laborer', 'effective_from' => '2026-08-01'])->assertCreated();
    }

    public function test_pay_site_manager_rule_and_termination_inside_a_locked_period_are_refused(): void
    {
        $eng = $this->employee('engineer');
        $worker = $this->employee('permanent_worker');
        $left = $this->employee('permanent_worker', '2026-01-01', ['termination_date' => '2026-07-20']);
        $site = $this->workSite();
        $manager = $this->managerOf($site);
        $this->lock('engineer');
        $this->lock('permanent_worker');

        $this->assertRefused($this->postJson("/api/v1/employees/{$eng}/pay-changes", ['effective_from' => '2026-07-10', 'salary' => '9500']), self::LOCKED);
        // A row dated before the locked period governs it too.
        $this->assertRefused($this->postJson("/api/v1/employees/{$eng}/pay-changes", ['effective_from' => '2026-06-01', 'salary' => '9500']), self::LOCKED);
        $this->assertRefused($this->postJson("/api/v1/employees/{$worker}/site-assignments", ['site_id' => $site, 'effective_from' => '2026-07-10']), self::LOCKED);
        $this->assertRefused($this->postJson("/api/v1/employees/{$eng}/manager-assignments", ['manager_id' => $manager->id, 'effective_from' => '2026-07-10']), self::LOCKED);
        $this->assertRefused($this->postJson("/api/v1/sites/{$site}/allowance-rules", ['effective_from' => '2026-07-10', 'mode' => 'fixed', 'value' => '20']), self::LOCKED);
        $this->assertRefused($this->patchJson("/api/v1/employees/{$eng}", ['termination_date' => '2026-07-10']), self::LOCKED, 'termination_date');
        // Clearing a termination date inside the locked period re-employs locked days.
        $this->assertRefused($this->patchJson("/api/v1/employees/{$left}", ['termination_date' => null]), self::LOCKED, 'termination_date');
    }

    public function test_dated_changes_after_the_locked_period_are_accepted(): void
    {
        $eng = $this->employee('engineer');
        $worker = $this->employee('permanent_worker');
        $site = $this->workSite();
        $manager = $this->managerOf($site);
        $this->lock('engineer');
        $this->lock('permanent_worker');

        $this->postJson("/api/v1/employees/{$eng}/pay-changes", ['effective_from' => '2026-07-26', 'salary' => '9500'])->assertCreated();
        $this->postJson("/api/v1/employees/{$eng}/manager-assignments", ['manager_id' => $manager->id, 'effective_from' => '2026-07-26'])->assertCreated();
        $this->postJson("/api/v1/employees/{$worker}/site-assignments", ['site_id' => $site, 'effective_from' => '2026-07-26'])->assertCreated();
        $this->postJson("/api/v1/sites/{$site}/allowance-rules", ['effective_from' => '2026-07-26', 'mode' => 'fixed', 'value' => '20'])->assertCreated();
        $this->postJson("/api/v1/employees/{$eng}/type-changes", ['type' => 'permanent_worker', 'effective_from' => '2026-07-26'])->assertCreated();
        $this->patchJson("/api/v1/employees/{$worker}", ['termination_date' => '2026-07-26'])->assertOk();
    }
}
