<?php

use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Presentation\EmployeeController;
use App\Modules\Sites\Presentation\FareController;
use App\Modules\Sites\Presentation\ResidenceController;
use App\Modules\Sites\Presentation\SiteController;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn (Request $request) => ApiEnvelope::single($request, ['status' => 'ok']));

Route::prefix('employees')->controller(EmployeeController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::middleware([])->whereUuid('employee')->group(function (): void {
        Route::get('{employee}', 'show');
        Route::patch('{employee}', 'update');
        Route::delete('{employee}', 'destroy');
        Route::post('{employee}/type-changes', 'typeChange');
        Route::post('{employee}/pay-changes', 'payChange');
        Route::put('{employee}/documents/{kind}', 'document')
            ->whereIn('kind', array_column(DocumentKind::cases(), 'value'));
    });
});

Route::prefix('employees')->whereUuid('employee')->group(function (): void {
    Route::post('{employee}/fare-overrides', [FareController::class, 'override']);
    Route::get('{employee}/fare', [FareController::class, 'show']);
});

Route::prefix('sites')->controller(SiteController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::middleware([])->whereUuid('site')->group(function (): void {
        Route::get('{site}', 'show');
        Route::patch('{site}', 'update');
        Route::delete('{site}', 'destroy');
        Route::post('{site}/allowance-rules', 'allowanceRule');
    });
});

Route::prefix('residences')->controller(ResidenceController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::middleware([])->whereUuid('residence')->group(function (): void {
        Route::get('{residence}', 'show');
        Route::patch('{residence}', 'update');
        Route::delete('{residence}', 'destroy');
    });
});

Route::prefix('fares')->controller(FareController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
});
