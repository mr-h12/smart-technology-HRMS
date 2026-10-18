<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Database\Seeders\AttendanceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class AttendanceDemoSeederTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    public function test_demo_seeder_is_repeatable_and_refuses_production(): void
    {
        $this->cairo('2026-10-20 12:00');
        $this->seed(AttendanceDemoSeeder::class);
        $this->seed(AttendanceDemoSeeder::class);

        $this->assertSame(9, Employee::count());
        $this->assertSame(1, OfficialHoliday::count());
        $this->assertSame(3, AttendanceDay::count());
        $this->assertSame('500.000000', AttendanceDay::where('date', '2026-10-04')->value('advance_amount'));
        $this->assertSame('absent', Site::find(AttendanceDay::where('date', '2026-10-05')->value('site_id'))?->leave_code);

        // Called directly: db:seed itself would stop at its production confirmation prompt first.
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(RuntimeException::class);
        $this->app->make(AttendanceDemoSeeder::class)->run();
    }
}
