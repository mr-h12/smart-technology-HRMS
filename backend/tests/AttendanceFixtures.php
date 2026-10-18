<?php

namespace Tests;

use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/** Attendance test data, labelled (no personal data). Use in a TestCase with RefreshDatabase. */
trait AttendanceFixtures
{
    /** @var array<string, int> */
    private array $employeesMade = [];

    /** @return array<string, string> leave_code => site id, for all seven codes */
    protected function leaveTypes(): array
    {
        // The Saturday migration already created the active «اجازة السبت»; a second one would break the one-per-code index.
        $ids = ['saturday' => (string) Site::where(['kind' => 'leave', 'leave_code' => 'saturday', 'active' => true])->value('id')];
        foreach (LeaveCode::cases() as $code) {
            if ($code !== LeaveCode::Saturday) {
                $ids[$code->value] = (string) Site::create(['name_ar' => 'Leave-'.$code->value, 'kind' => 'leave', 'leave_code' => $code->value])->getKey();
            }
        }

        return $ids;
    }

    protected function workSite(string $name = 'Site-1', bool $outside = false, bool $active = true): string
    {
        return (string) Site::create(['name_ar' => $name, 'kind' => 'work', 'outside_alexandria' => $outside, 'active' => $active])->getKey();
    }

    /** @param  array<string, mixed>  $attributes  identity or pay fields (e.g. termination_date, shift_start) */
    protected function employee(string $type, string $from = '2026-01-01', array $attributes = []): string
    {
        $label = ['engineer' => 'Engineer', 'permanent_worker' => 'Worker', 'office' => 'Office', 'day_laborer' => 'Laborer'][$type];
        $n = $this->employeesMade[$type] = ($this->employeesMade[$type] ?? 0) + 1;
        $pay = $type === 'day_laborer' ? ['daily_wage' => '330'] : ['salary' => '9000'];

        return app(EmployeeService::class)->create([
            'name_ar' => "{$label}-{$n}", 'gender' => 'male', 'type' => $type, 'effective_from' => $from, 'hire_date' => $from, ...$pay, ...$attributes,
        ])['id'];
    }

    protected function holiday(string $date, string $name = 'Holiday-1'): string
    {
        return (string) OfficialHoliday::create(['date' => $date, 'name' => $name])->getKey();
    }

    protected function cairo(string $local): void
    {
        $this->travelTo(Carbon::parse($local, 'Africa/Cairo'));
    }

    /** @param  array<string, mixed>  $body */
    protected function putDay(string $employeeId, string $date, array $body): TestResponse
    {
        return $this->putJson("/api/v1/attendance/days/{$employeeId}/{$date}", $body);
    }
}
