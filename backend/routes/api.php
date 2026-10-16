<?php

use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Presentation\EmployeeController;
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
