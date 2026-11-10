<?php

namespace Tests;

use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Attendance test data, labelled (no personal data). Use in a TestCase with RefreshDatabase. */
trait AttendanceFixtures
{
    /** The 409 concurrency_conflict text per locale, spelled out so a moved or renamed lang key cannot change it unnoticed. */
    protected const CONFLICT_MESSAGE = [
        'ar' => 'عدّل مستخدم آخر هذا السجل. حمّل آخر نسخة ثم أعد المحاولة.',
        'en' => 'Someone else changed this record. Load the latest version and try again.',
    ];

    /** @var array<string, int> */
    private array $employeesMade = [];

    /** @return array<string, string> leave_code => site id, for every code */
    protected function leaveTypes(): array
    {
        // Migrations already created some active leave types (Saturday, annual, casual, unpaid); a second one would break the one-per-code index.
        $ids = [];
        foreach (LeaveCode::cases() as $code) {
            $ids[$code->value] = (string) (Site::where(['kind' => 'leave', 'leave_code' => $code->value, 'active' => true])->value('id')
                ?? Site::create(['name_ar' => 'Leave-'.$code->value, 'kind' => 'leave', 'leave_code' => $code->value])->getKey());
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

    /** A user with the manager role who manages these sites. */
    protected function managerOf(string ...$siteIds): User
    {
        $manager = $this->makeUser('manager');
        DB::table('manager_sites')->insert(array_map(fn (string $id) => ['manager_id' => $manager->id, 'site_id' => $id], $siteIds));

        return $manager;
    }

    /** Homes a worker on a site from this date, as HR's move does. */
    protected function homeSite(string $employeeId, string $siteId, string $from): void
    {
        app(EmployeeService::class)->assignSite($employeeId, ['site_id' => $siteId, 'effective_from' => $from]);
    }

    /** A holiday from $date to $end (default: that one day). */
    protected function holiday(string $date, string $name = 'Holiday-1', ?string $end = null): string
    {
        return (string) OfficialHoliday::create(['date' => $date, 'end_date' => $end ?? $date, 'name' => $name])->getKey();
    }

    /** A user whose role holds attendance.view and attendance.edit at these scopes; null = not held. */
    protected function userWith(?string $view, ?string $edit): User
    {
        $slug = 'lead-'.($view ?? 'none').'-'.($edit ?? 'none');
        $role = Role::create(['slug' => $slug, 'name' => $slug, 'name_ar' => $slug]);
        foreach (['view' => $view, 'edit' => $edit] as $action => $scope) {
            if ($scope !== null) {
                $role->permissions()->attach(Permission::firstOrCreate(['resource' => 'attendance', 'action' => $action, 'scope' => $scope])->getKey());
            }
        }

        return $this->makeUser($slug);
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
