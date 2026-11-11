<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_every_write_keyed_by_an_archived_employee_is_refused(): void
    {
        $this->cairo('2026-10-20 12:00');
        $left = $this->employee('office', '2026-01-01', ['termination_date' => '2026-10-01']);
        $doc = (string) Str::uuid();                                                                // the guard runs before the controller
        $audit = DB::table('audit_log')->count();

        foreach ([
            ['PATCH', "employees/{$left}/code"],
            ['POST', "employees/{$left}/type-changes"],
            ['POST', "employees/{$left}/pay-changes"],
            ['POST', "employees/{$left}/manager-assignments"],
            ['POST', "employees/{$left}/site-assignments"],
            ['POST', "employees/{$left}/fare-overrides"],
            ['POST', "employees/{$left}/documents"],
            ['PATCH', "employees/{$left}/documents/{$doc}"],
            ['DELETE', "employees/{$left}/documents/{$doc}"],
            ['PUT', "employees/{$left}/leave/2026"],
        ] as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}")->assertStatus(409)->assertJsonPath('error.code', 'employee_archived');
        }
        $this->withHeader('Accept-Language', 'en')->postJson("/api/v1/employees/{$left}/type-changes")->assertStatus(409)
            ->assertJsonPath('error.message', 'This employee has left (archived), so this change is not allowed.');
        $this->assertSame($audit, DB::table('audit_log')->count());
    }

    public function test_delete_and_days_up_to_the_termination_date_still_write_for_an_archived_employee(): void
    {
        $this->cairo('2026-10-20 12:00');
        $left = $this->employee('office', '2026-01-01', ['termination_date' => '2026-10-01']);

        $this->putDay($left, '2026-10-01', ['site_id' => null, 'in_time' => '09:00'])->assertOk();
        $this->putDay($left, '2026-10-02', ['site_id' => null, 'in_time' => '09:00'])->assertStatus(422)->assertJsonPath('error.code', 'employee_not_active');
        $this->deleteJson("/api/v1/employees/{$left}")->assertNoContent();
    }

    public function test_an_archived_employee_may_only_have_the_termination_date_and_reason_changed(): void
    {
        $this->cairo('2026-10-20 12:00');
        $left = $this->employee('office', '2026-01-01', [
            'termination_date' => '2026-10-01', 'termination_reason' => 'Reason-1', 'residence_id' => (string) Residence::create(['name_ar' => 'Residence-1'])->getKey(),
            'phone' => '01012345678', 'hr_notes' => 'Note-1',
        ]);
        // The profile form sends every identity field on each save (frontend employees.tsx): the PATCH is judged on changed values.
        $body = Arr::only($this->getJson("/api/v1/employees/{$left}")->json('data'), [
            'name_ar', 'name_en', 'fingerprint_name', 'gender', 'national_id', 'insurance_no', 'phone', 'address', 'department', 'residence_id',
            'pay_method', 'bank_account', 'hire_date', 'contract_end_date', 'termination_date', 'termination_reason', 'hr_notes',
        ]);

        $this->patchJson("/api/v1/employees/{$left}", [...$body, 'termination_reason' => 'Reason-2'])->assertOk()->assertJsonPath('data.termination_reason', 'Reason-2');
        $this->patchJson("/api/v1/employees/{$left}", [...$body, 'name_ar' => 'Office-X'])->assertStatus(409)->assertJsonPath('error.code', 'employee_archived');
        $this->assertSame('Office-1', Employee::whereKey($left)->value('name_ar'));
        $this->patchJson("/api/v1/employees/{$left}", ['termination_date' => '2026-09-30'])->assertOk()->assertJsonPath('data.termination_date', '2026-09-30');
        $this->patchJson("/api/v1/employees/{$left}", ['termination_date' => null, 'termination_reason' => null])->assertOk()->assertJsonPath('data.archived', false);
        $this->patchJson("/api/v1/employees/{$left}", ['name_ar' => 'Office-X'])->assertOk()->assertJsonPath('data.name_ar', 'Office-X');
    }

    public function test_an_active_employee_is_archived_and_edited_in_one_save(): void
    {
        $this->cairo('2026-10-20 12:00');
        $id = $this->employee('office');

        $this->patchJson("/api/v1/employees/{$id}", ['name_ar' => 'Office-X', 'termination_date' => '2026-10-01'])->assertOk()
            ->assertJsonPath('data.name_ar', 'Office-X')->assertJsonPath('data.termination_date', '2026-10-01')->assertJsonPath('data.archived', true);
    }
}
