<?php

use App\Modules\Attendance\Presentation\AttendanceController;
use App\Modules\Attendance\Presentation\AttendanceLockController;
use App\Modules\Attendance\Presentation\HolidayController;
use App\Modules\Audit\Presentation\AuditLogController;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Presentation\EmployeeController;
use App\Modules\Identity\Presentation\AuthController;
use App\Modules\Identity\Presentation\RoleController;
use App\Modules\Identity\Presentation\UserController;
use App\Modules\Settings\Presentation\SettingController;
use App\Modules\Sites\Presentation\FareController;
use App\Modules\Sites\Presentation\ResidenceController;
use App\Modules\Sites\Presentation\SiteController;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (Request $request) => ApiEnvelope::single($request, ['status' => 'ok']));

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Everything else needs a session; every route but auth/* also names its permission.
Route::middleware('auth')->group(function (): void {
    Route::prefix('auth')->controller(AuthController::class)->group(function (): void {
        Route::post('logout', 'logout');
        Route::get('me', 'me');
    });

    Route::prefix('employees')->controller(EmployeeController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:employee.view');
        Route::get('summary', 'summary')->middleware('permission:employee.view');
        Route::post('/', 'store')->middleware('permission:employee.create');
        Route::middleware([])->whereUuid('employee')->group(function (): void {
            Route::get('{employee}', 'show')->middleware(['permission:employee.view', 'employee.scope']);
            Route::patch('{employee}', 'update')->middleware(['permission:employee.edit', 'employee.scope']);
            Route::delete('{employee}', 'destroy')->middleware(['permission:employee.delete', 'employee.scope']);
            Route::post('{employee}/type-changes', 'typeChange')->middleware(['permission:employee.edit', 'employee.scope']);
            Route::post('{employee}/pay-changes', 'payChange')->middleware(['permission:employee.edit', 'employee.scope']);
            Route::post('{employee}/manager-assignments', 'assignManager')->middleware(['permission:employee.assign_manager', 'employee.scope']);
            Route::put('{employee}/documents/{kind}', 'document')->middleware(['permission:employee.edit', 'employee.scope'])
                ->whereIn('kind', array_column(DocumentKind::cases(), 'value'));
        });
    });

    Route::prefix('employees')->whereUuid('employee')->group(function (): void {
        Route::post('{employee}/fare-overrides', [FareController::class, 'override'])->middleware(['permission:employee.edit', 'employee.scope']);
        Route::get('{employee}/fare', [FareController::class, 'show'])->middleware(['permission:employee.view', 'employee.scope']);
    });

    Route::prefix('sites')->controller(SiteController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:site.view');
        Route::post('/', 'store')->middleware('permission:site.manage');
        Route::middleware([])->whereUuid('site')->group(function (): void {
            Route::get('{site}', 'show')->middleware('permission:site.view');
            Route::patch('{site}', 'update')->middleware('permission:site.manage');
            Route::delete('{site}', 'destroy')->middleware('permission:site.manage');
            Route::post('{site}/allowance-rules', 'allowanceRule')->middleware('permission:site.manage');
            Route::put('{site}/managers', 'managers')->middleware('permission:site.assign_manager');
        });
    });

    Route::prefix('residences')->controller(ResidenceController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:site.view');
        Route::post('/', 'store')->middleware('permission:site.manage');
        Route::middleware([])->whereUuid('residence')->group(function (): void {
            Route::get('{residence}', 'show')->middleware('permission:site.view');
            Route::patch('{residence}', 'update')->middleware('permission:site.manage');
            Route::delete('{residence}', 'destroy')->middleware('permission:site.manage');
        });
    });

    Route::prefix('fares')->controller(FareController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:site.view');
        Route::post('/', 'store')->middleware('permission:site.manage');
    });

    Route::prefix('attendance')->controller(AttendanceController::class)->group(function (): void {
        Route::get('period', 'period')->middleware('permission:attendance.view');
        Route::get('grid', 'grid')->middleware('permission:attendance.view');
        Route::get('missing', 'missing')->middleware('permission:attendance.view');
        Route::get('sheet', 'sheet')->middleware('permission:attendance.view');
        Route::get('template', 'template')->middleware('permission:attendance.edit');
        Route::post('days/bulk', 'bulk')->middleware('permission:attendance.edit');
        // A registrar where() would replace whereUuid(), so the date shape goes on each route.
        Route::middleware([])->whereUuid('employee')->group(function (): void {
            $date = '\d{4}-\d{2}-\d{2}';
            Route::get('days/{employee}/{date}', 'show')->middleware(['permission:attendance.view', 'employee.scope'])->where('date', $date);
            Route::put('days/{employee}/{date}', 'update')->middleware(['permission:attendance.edit', 'employee.scope'])->where('date', $date);
            Route::delete('days/{employee}/{date}', 'destroy')->middleware(['permission:attendance.edit', 'employee.scope'])->where('date', $date);
        });
    });

    Route::prefix('attendance/locks')->controller(AttendanceLockController::class)->group(function (): void {
        Route::post('/', 'store')->middleware('permission:attendance.lock');
        Route::post('reopen', 'reopen')->middleware('permission:attendance.lock');
    });

    Route::prefix('attendance/holidays')->controller(HolidayController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:attendance.view');
        Route::post('/', 'store')->middleware('permission:holiday.manage');
        Route::middleware([])->whereUuid('holiday')->group(function (): void {
            Route::patch('{holiday}', 'update')->middleware('permission:holiday.manage');
            Route::delete('{holiday}', 'destroy')->middleware('permission:holiday.manage');
        });
    });

    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit_log.view');

    Route::get('settings', [SettingController::class, 'index'])->middleware('permission:setting.view');
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:setting.manage');

    Route::prefix('users')->controller(UserController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:user.view');
        Route::post('/', 'store')->middleware('permission:user.manage');
        Route::middleware([])->whereUuid(['user', 'session'])->group(function (): void {
            Route::get('{user}', 'show')->middleware('permission:user.view');
            Route::patch('{user}', 'update')->middleware('permission:user.manage');
            Route::patch('{user}/deactivate', 'deactivate')->middleware('permission:user.manage');
            Route::patch('{user}/reactivate', 'reactivate')->middleware('permission:user.manage');
            Route::put('{user}/password', 'password')->middleware('permission:user.manage');
            Route::get('{user}/sessions', 'sessions')->middleware('permission:user.manage');
            Route::delete('{user}/sessions/{session}', 'terminateSession')->middleware('permission:user.manage');
        });
    });

    Route::controller(RoleController::class)->group(function (): void {
        Route::get('roles', 'index')->middleware('permission:role.view');
        Route::get('permissions', 'permissions')->middleware('permission:role.view');
        Route::post('roles', 'store')->middleware('permission:role.manage');
        Route::middleware([])->whereUuid('role')->group(function (): void {
            Route::patch('roles/{role}', 'update')->middleware('permission:role.manage');
            Route::patch('roles/{role}/permissions', 'syncPermissions')->middleware('permission:role.manage');
        });
    });
});
