<?php

use App\Modules\Attendance\Presentation\AttendanceController;
use App\Modules\Attendance\Presentation\AttendanceLockController;
use App\Modules\Attendance\Presentation\AttendanceRequestController;
use App\Modules\Attendance\Presentation\HolidayController;
use App\Modules\Attendance\Presentation\SiteDayController;
use App\Modules\Attendance\Presentation\SiteDayCrewController;
use App\Modules\Audit\Presentation\AuditLogController;
use App\Modules\Audit\Presentation\ErrorLogController;
use App\Modules\Audit\Presentation\RequestLogController;
use App\Modules\Documents\Application\ManualFormDefinitions;
use App\Modules\Documents\Presentation\AttendanceRequestFormController;
use App\Modules\Documents\Presentation\ExperienceCertificateController;
use App\Modules\Documents\Presentation\LeaveRequestFormController;
use App\Modules\Documents\Presentation\ManualHrFormController;
use App\Modules\Documents\Presentation\SignatureController;
use App\Modules\Employees\Presentation\EmployeeController;
use App\Modules\Employees\Presentation\EmployeeDocumentController;
use App\Modules\Employees\Presentation\WageProposalController;
use App\Modules\Identity\Presentation\AuthController;
use App\Modules\Identity\Presentation\RoleController;
use App\Modules\Identity\Presentation\UserController;
use App\Modules\Leave\Presentation\LeaveBalanceController;
use App\Modules\Leave\Presentation\LeaveRequestController;
use App\Modules\Notifications\Presentation\NotificationController;
use App\Modules\Payroll\Presentation\PayrollController;
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
        Route::post('view-as', 'startViewAs')->middleware('permission:user.impersonate');
        Route::post('view-as/end', 'endViewAs');
    });

    Route::controller(SignatureController::class)->middleware('permission:hr_signatures.manage')->group(function (): void {
        Route::get('me/hr-signature', 'show');
        Route::get('me/hr-signature/file', 'file');
        Route::post('me/hr-signature', 'store');
    });

    Route::get('employees/{employee}/hr-forms/experience-certificate/defaults', [ExperienceCertificateController::class, 'defaults'])
        ->whereUuid('employee')->middleware(['permission:hr_forms.generate', 'employee.scope']);
    Route::post('employees/{employee}/hr-forms/experience-certificate', [ExperienceCertificateController::class, 'store'])
        ->whereUuid('employee')->middleware(['permission:hr_forms.generate', 'employee.scope']);
    Route::get('hr-manual-form-definitions', [ManualHrFormController::class, 'definitions'])
        ->middleware('permission:hr_forms.view,hr_forms.generate');
    $candidateForms = array_keys(array_filter(ManualFormDefinitions::all(), fn (array $definition): bool => $definition['candidate'] ?? false));
    $employeeForms = array_diff(array_keys(ManualFormDefinitions::all()), $candidateForms);
    Route::post('hr-forms/manual-candidate/{form}', [ManualHrFormController::class, 'storeCandidate'])
        ->whereIn('form', $candidateForms)->middleware('permission:hr_forms.generate');
    Route::get('hr-forms/manual-candidate/{form}', [ManualHrFormController::class, 'candidates'])
        ->whereIn('form', $candidateForms)->middleware('permission:hr_forms.view');
    Route::post('employees/{employee}/hr-forms/manual/{form}', [ManualHrFormController::class, 'store'])
        ->whereUuid('employee')->whereIn('form', array_values($employeeForms))
        ->middleware(['permission:hr_forms.generate', 'employee.scope']);
    Route::post('hr-form-exports/{export}/retry-manual/{form}', [ManualHrFormController::class, 'retry'])
        ->whereUuid('export')->whereIn('form', array_keys(ManualFormDefinitions::all()))->middleware('permission:hr_forms.generate');
    Route::get('employees/{employee}/hr-form-exports', [ExperienceCertificateController::class, 'history'])
        ->whereUuid('employee')->middleware(['permission:hr_forms.view', 'employee.scope']);
    Route::get('hr-form-exports/awaiting-approval', [ExperienceCertificateController::class, 'awaitingApproval'])
        ->middleware('permission:hr_forms.approve');
    Route::get('hr-form-exports/{export}/download', [ExperienceCertificateController::class, 'download'])
        ->whereUuid('export')->middleware('permission:hr_forms.view');
    Route::get('hr-form-exports/{export}', [ExperienceCertificateController::class, 'show'])
        ->whereUuid('export')->middleware('permission:hr_forms.view');

    Route::post('hr-form-exports/{export}/approve', [ExperienceCertificateController::class, 'approve'])
        ->whereUuid('export')->middleware('permission:hr_forms.approve');
    Route::post('hr-form-exports/{export}/retry', [ExperienceCertificateController::class, 'retry'])
        ->whereUuid('export')->middleware('permission:hr_forms.approve');

    Route::post('leave/requests/{leaveRequest}/hr-forms/leave-request', [LeaveRequestFormController::class, 'store'])
        ->whereUuid('leaveRequest')->middleware('permission:hr_forms.generate');
    Route::get('leave/requests/{leaveRequest}/hr-form-exports', [LeaveRequestFormController::class, 'index'])
        ->whereUuid('leaveRequest')->middleware('permission:hr_forms.view');
    Route::post('hr-form-exports/{export}/approve-leave-request', [LeaveRequestFormController::class, 'approve'])
        ->whereUuid('export')->middleware('permission:hr_forms.approve_leave_request');
    Route::post('hr-form-exports/{export}/retry-leave-request', [LeaveRequestFormController::class, 'retry'])
        ->whereUuid('export')->middleware('permission:hr_forms.approve_leave_request');

    Route::prefix('attendance/requests')->controller(AttendanceRequestController::class)->middleware('permission:attendance_request.decide')->whereUuid('id')->group(function (): void {
        Route::get('/', 'index');
        Route::get('{id}', 'show');
        Route::post('{id}/approve', 'approve');
        Route::post('{id}/refuse', 'refuse');
    });

    Route::post('attendance/requests/{id}/hr-forms/advance-request', [AttendanceRequestFormController::class, 'store'])
        ->whereUuid('id')->middleware('permission:hr_forms.generate');
    Route::get('attendance/requests/{id}/hr-form-exports', [AttendanceRequestFormController::class, 'index'])
        ->whereUuid('id')->middleware('permission:hr_forms.view');
    Route::post('hr-form-exports/{export}/retry-advance-request', [AttendanceRequestFormController::class, 'retry'])
        ->whereUuid('export')->middleware('permission:hr_forms.generate');

    Route::post('attendance/requests/{id}/hr-forms/overtime-request', [AttendanceRequestFormController::class, 'store'])
        ->whereUuid('id')->defaults('type', 'overtime')->middleware('permission:hr_forms.generate');
    Route::post('hr-form-exports/{export}/retry-overtime-request', [AttendanceRequestFormController::class, 'retry'])
        ->whereUuid('export')->defaults('type', 'overtime')->middleware('permission:hr_forms.generate');

    Route::prefix('employees/wage-proposals')->controller(WageProposalController::class)->whereUuid('proposal')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:employee.approve_wage');
        Route::post('{proposal}/approve', 'approve')->middleware('permission:employee.approve_wage');
        Route::post('{proposal}/refuse', 'refuse')->middleware('permission:employee.approve_wage');
    });

    Route::prefix('employees')->controller(EmployeeController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:employee.view');
        Route::get('summary', 'summary')->middleware('permission:employee.view');
        // The accounts the make-site-manager dialog may link: POST {employee}/site-manager's guards, less employee.scope (no employee yet).
        Route::get('site-manager-candidates', 'siteManagerCandidates')
            ->middleware(['permission:user.manage', 'permission:site.assign_manager', 'permission:employee.assign_manager']);
        Route::post('/', 'store')->middleware('permission:employee.create');
        Route::middleware([])->whereUuid('employee')->group(function (): void {
            Route::get('{employee}', 'show')->middleware(['permission:employee.view', 'employee.scope']);
            // archived_ok: these routes judge an archived employee themselves (EnsureEmployeeInScope).
            Route::patch('{employee}', 'update')->middleware(['permission:employee.edit', 'employee.scope:archived_ok']);
            Route::delete('{employee}', 'destroy')->middleware(['permission:employee.delete', 'employee.scope:archived_ok']);
            Route::patch('{employee}/code', 'changeCode')->middleware(['permission:employee.edit_code', 'employee.scope']);
            Route::post('{employee}/type-changes', 'typeChange')->middleware(['permission:employee.edit', 'employee.scope']);
            Route::post('{employee}/pay-changes', 'payChange')->middleware(['permission:employee.edit', 'employee.scope']);
            Route::post('{employee}/manager-assignments', 'assignManager')->middleware(['permission:employee.assign_manager', 'employee.scope']);
            Route::post('{employee}/site-assignments', 'assignSite')->middleware(['permission:employee.assign_site', 'employee.scope']);
            // Make site manager (owner, 2026-11-12): POST creates or links the account, PUT changes its sites and adds engineers.
            Route::post('{employee}/site-manager', 'makeSiteManager')
                ->middleware(['permission:user.manage', 'permission:site.assign_manager', 'permission:employee.assign_manager', 'employee.scope']);
            Route::put('{employee}/site-manager', 'updateSiteManager')
                ->middleware(['permission:site.assign_manager', 'permission:employee.assign_manager', 'employee.scope']);
        });
    });

    Route::prefix('employees')->whereUuid('employee')->group(function (): void {
        Route::post('{employee}/fare-overrides', [FareController::class, 'override'])->middleware(['permission:fare.manage', 'employee.scope']);
        Route::get('{employee}/fare', [FareController::class, 'show'])->middleware(['permission:employee.view', 'employee.scope']);
        Route::post('{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware(['permission:employee_document.edit', 'employee.scope']);
        Route::patch('{employee}/documents/{document}', [EmployeeDocumentController::class, 'update'])->middleware(['permission:employee_document.edit', 'employee.scope'])->whereUuid('document');
        Route::get('{employee}/documents/{document}/file', [EmployeeDocumentController::class, 'file'])->middleware(['permission:employee_document.view', 'employee.scope'])->whereUuid('document');
        Route::delete('{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware(['permission:employee_document.edit', 'employee.scope'])->whereUuid('document');
    });

    Route::prefix('sites')->controller(SiteController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:site.view');
        Route::post('/', 'store')->middleware('permission:site.manage');
        Route::get('manager-candidates', 'managerCandidates')->middleware('permission:site.assign_manager');
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
        Route::post('/', 'store')->middleware('permission:fare.manage');
    });

    Route::get('team-fares', [FareController::class, 'team'])->middleware('permission:employee.view');

    Route::prefix('attendance')->controller(AttendanceController::class)->group(function (): void {
        Route::get('period', 'period')->middleware('permission:attendance.view');
        Route::get('grid', 'grid')->middleware('permission:attendance.view');
        Route::get('missing', 'missing')->middleware('permission:attendance.view');
        Route::get('sheet', 'sheet')->middleware('permission:attendance.view');
        Route::get('template', 'template')->middleware(['permission:attendance.view', 'permission:attendance.edit']);
        Route::post('import/preview', 'preview')->middleware(['permission:attendance.view', 'permission:attendance.edit']);
        Route::post('import/confirm', 'confirm')->middleware(['permission:attendance.view', 'permission:attendance.edit']);
        Route::post('days/bulk', 'bulk')->middleware('permission:attendance.edit');
        // A registrar where() would replace whereUuid(), so the date shape goes on each route.
        Route::middleware([])->whereUuid('employee')->group(function (): void {
            $date = '\d{4}-\d{2}-\d{2}';
            Route::get('days/{employee}/{date}', 'show')->middleware(['permission:attendance.view', 'employee.scope'])->where('date', $date);
            // archived_ok: a day up to the termination date is still written (Employment refuses later ones).
            Route::put('days/{employee}/{date}', 'update')->middleware(['permission:attendance.edit', 'employee.scope:archived_ok'])->where('date', $date);
            Route::delete('days/{employee}/{date}', 'destroy')->middleware(['permission:attendance.edit', 'employee.scope:archived_ok'])->where('date', $date);
            Route::get('employees/{employee}/period', 'employeePeriod')->middleware(['permission:attendance.view', 'employee.scope']);
        });
    });

    Route::get('attendance/site-day', [SiteDayController::class, 'show'])->middleware('permission:attendance.record');
    Route::post('attendance/site-day', [SiteDayController::class, 'store'])->middleware('permission:attendance.record');
    Route::prefix('attendance/site-day')->controller(SiteDayCrewController::class)->group(function (): void {
        Route::get('laborers', 'search')->middleware(['permission:attendance.record', 'permission:employee.crew']);
        Route::post('crew', 'update')->middleware(['permission:attendance.record', 'permission:employee.crew']);
        Route::post('laborers', 'store')->middleware(['permission:attendance.record', 'permission:employee.propose_laborer']);
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

    // No `.own` scope: payroll is HR's and the CEO's (design 2026-11-09), so site managers get 403. The controller checks the period.
    Route::prefix('payroll/{group}/{period_start}')->controller(PayrollController::class)->whereUuid(['employee', 'override'])->group(function (): void {
        Route::get('/', 'sheet')->middleware('permission:payroll.view');
        Route::get('employees/{employee}', 'employee')->middleware('permission:payroll.view');
        Route::put('employees/{employee}/entry', 'entry')->middleware('permission:payroll.manage');
        Route::post('employees/{employee}/overrides', 'addOverride')->middleware('permission:payroll.manage');
        Route::delete('employees/{employee}/overrides/{override}', 'revertOverride')->middleware('permission:payroll.manage');
    });

    // Auth only: each account sees and marks only its own rows (the inbox filters by user).
    Route::prefix('notifications')->controller(NotificationController::class)->group(function (): void {
        Route::get('/', 'index');
        Route::patch('{notification}/read', 'read')->whereUuid('notification');
    });

    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit_log.view');
    Route::get('request-log', [RequestLogController::class, 'index'])->middleware('permission:system_log.view');
    Route::get('error-log', [ErrorLogController::class, 'index'])->middleware('permission:system_log.view');

    Route::get('settings', [SettingController::class, 'index'])->middleware('permission:setting.view');
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:setting.manage');

    Route::prefix('users')->controller(UserController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:user.view');
        Route::post('/', 'store')->middleware('permission:user.manage');
        Route::get('assignable-roles', 'assignableRoles')->middleware('permission:user.manage');
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

    Route::get('employees/{employee}/leave', [LeaveBalanceController::class, 'show'])->middleware(['permission:leave.view', 'employee.scope'])->whereUuid('employee');
    Route::put('employees/{employee}/leave/{year}', [LeaveBalanceController::class, 'adjust'])->middleware(['permission:leave.adjust', 'employee.scope'])
        ->whereUuid('employee')->where('year', '[0-9]{4}');
    Route::get('leave/balances', [LeaveBalanceController::class, 'index'])->middleware('permission:leave.view');

    Route::prefix('leave/requests')->controller(LeaveRequestController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:leave.view');
        Route::get('preview', 'preview')->middleware('permission:leave.request');
        Route::post('/', 'store')->middleware('permission:leave.request');
        Route::get('{id}', 'show')->middleware('permission:leave.view')->whereUuid('id');
        // The requester or leave.request.all while undecided, leave.decide.all once approved; LeaveRequests checks it.
        Route::post('{id}/cancel', 'cancel')->middleware('permission:leave.view')->whereUuid('id');
        // Deciding also needs the permission the request's status asks for (pending: leave.decide, escalated: leave.decide_escalated); LeaveRequests checks it.
        Route::post('{id}/{action}', 'decide')->middleware('permission:leave.view')->whereUuid('id')->whereIn('action', ['approve', 'refuse', 'escalate']);
    });
});
