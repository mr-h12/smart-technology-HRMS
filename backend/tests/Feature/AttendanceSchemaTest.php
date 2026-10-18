<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_days_enforce_their_constraints(): void
    {
        $employeeId = app(EmployeeService::class)->create(['name_ar' => 'Office-1', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000'])['id'];
        // savepoint per insert: one refusal must not abort the next statement
        $insert = fn (array $over = []) => DB::transaction(fn () => DB::table('attendance_days')->insert([
            'id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'date' => '2026-10-01', 'source' => 'entered', ...$over,
        ]));

        $insert();
        try {
            $insert();
            $this->fail('a second row for the same employee and date was accepted');
        } catch (UniqueConstraintViolationException) {
        }

        foreach ([['late_minutes' => 961], ['transport' => 'bus'], ['source' => 'typed'], ['advance_amount' => '0']] as $bad) {
            try {
                $insert(['date' => '2026-10-02', ...$bad]);
                $this->fail('accepted '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('check constraint', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('attendance_days')->count());
    }

    public function test_one_active_leave_type_per_code(): void
    {
        $this->signIn('hr');
        $first = $this->postJson('/api/v1/sites', ['name_ar' => 'Leave-1', 'kind' => 'leave', 'leave_code' => 'friday'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sites', ['name_ar' => 'Leave-2', 'kind' => 'leave', 'leave_code' => 'friday'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'leave_code');

        $this->patchJson("/api/v1/sites/$first", ['active' => false])->assertOk();
        $this->postJson('/api/v1/sites', ['name_ar' => 'Leave-2', 'kind' => 'leave', 'leave_code' => 'friday'])->assertCreated();

        // the Saturday migration's «اجازة السبت» already holds the code
        $this->postJson('/api/v1/sites', ['name_ar' => 'Leave-3', 'kind' => 'leave', 'leave_code' => 'saturday'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'leave_code');
    }
}
