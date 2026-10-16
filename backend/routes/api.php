<?php

use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Presentation\EmployeeController;
use App\Modules\Identity\Presentation\AuthController;
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
        Route::post('/', 'store')->middleware('permission:employee.create');
        Route::middleware([])->whereUuid('employee')->group(function (): void {
            Route::get('{employee}', 'show')->middleware('permission:employee.view');
            Route::patch('{employee}', 'update')->middleware('permission:employee.edit');
            Route::delete('{employee}', 'destroy')->middleware('permission:employee.delete');
            Route::post('{employee}/type-changes', 'typeChange')->middleware('permission:employee.edit');
            Route::post('{employee}/pay-changes', 'payChange')->middleware('permission:employee.edit');
            Route::put('{employee}/documents/{kind}', 'document')->middleware('permission:employee.edit')
                ->whereIn('kind', array_column(DocumentKind::cases(), 'value'));
        });
    });

    Route::prefix('employees')->whereUuid('employee')->group(function (): void {
        Route::post('{employee}/fare-overrides', [FareController::class, 'override'])->middleware('permission:employee.edit');
        Route::get('{employee}/fare', [FareController::class, 'show'])->middleware('permission:employee.view');
    });

    Route::prefix('sites')->controller(SiteController::class)->group(function (): void {
        Route::get('/', 'index')->middleware('permission:site.view');
        Route::post('/', 'store')->middleware('permission:site.manage');
        Route::middleware([])->whereUuid('site')->group(function (): void {
            Route::get('{site}', 'show')->middleware('permission:site.view');
            Route::patch('{site}', 'update')->middleware('permission:site.manage');
            Route::delete('{site}', 'destroy')->middleware('permission:site.manage');
            Route::post('{site}/allowance-rules', 'allowanceRule')->middleware('permission:site.manage');
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
});
