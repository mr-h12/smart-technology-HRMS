<?php

namespace Database\Seeders;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Attendance\Application\OfficialHolidays;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Labelled demo data for the Release 1c API demo (`db:seed --class=AttendanceDemoSeeder`). Not called by DatabaseSeeder; safe to run twice. */
class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('AttendanceDemoSeeder never runs in production.');
        }

        $this->call(SitesSeeder::class);
        $site1 = (string) Site::firstOrCreate(['name_ar' => 'Site-1'], ['kind' => 'work'])->getKey();
        Site::firstOrCreate(['name_ar' => 'Site-2'], ['kind' => 'work', 'outside_alexandria' => true]);

        $employees = [
            'Office-1' => 'office', 'Office-2' => 'office',
            'Engineer-1' => 'engineer', 'Engineer-2' => 'engineer', 'Engineer-3' => 'engineer',
            'Worker-1' => 'permanent_worker', 'Worker-2' => 'permanent_worker',
            'Laborer-1' => 'day_laborer', 'Laborer-2' => 'day_laborer',
        ];
        foreach ($employees as $name => $type) {
            if (Employee::where('name_ar', $name)->doesntExist()) {
                $pay = match ($type) {
                    'day_laborer' => ['daily_wage' => '330'],
                    'permanent_worker' => ['salary' => '9000', 'shift_start' => '07:00', 'shift_end' => '15:00'],
                    default => ['salary' => '9000'],
                };
                app(EmployeeService::class)->create([
                    'name_ar' => $name, 'gender' => 'male', 'type' => $type, 'effective_from' => '2026-01-01', 'hire_date' => '2026-01-01', ...$pay,
                ]);
            }
        }

        if (OfficialHoliday::where('date', '2026-10-06')->doesntExist()) {
            app(OfficialHolidays::class)->create(['date' => '2026-10-06', 'name' => 'Holiday-1']);
        }

        $engineer = (string) Employee::where('name_ar', 'Engineer-1')->value('id');
        $absent = (string) Site::where(['kind' => 'leave', 'leave_code' => 'absent', 'active' => true])->value('id');
        $days = [
            '2026-10-01' => ['site_id' => $site1],
            '2026-10-04' => ['site_id' => $site1, 'advance_amount' => '500'],
            '2026-10-05' => ['site_id' => $absent],
        ];
        foreach ($days as $date => $input) {
            // A second save with version null on a live row is a concurrency conflict, so skip the days already there.
            if (AttendanceDay::where(['employee_id' => $engineer, 'date' => $date])->doesntExist()) {
                app(AttendanceDays::class)->save($engineer, $date, $input);
            }
        }
    }
}
