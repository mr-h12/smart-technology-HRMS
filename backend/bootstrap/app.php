<?php

use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\ForgetResolvedGuards;
use App\Http\Middleware\RecordRequest;
use App\Http\Middleware\SetLocaleFromRequest;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Presentation\AlertMissingDaysCommand;
use App\Modules\Audit\Infrastructure\SystemLog;
use App\Modules\Audit\Presentation\PruneLogsCommand;
use App\Modules\Employees\Domain\EmployeeArchived;
use App\Modules\Employees\Domain\EmployeeCodeTaken;
use App\Modules\Employees\Domain\EmployeeHasOpenItems;
use App\Modules\Employees\Presentation\EnsureEmployeeInScope;
use App\Modules\Employees\Presentation\ImportMasterDataCommand;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\AuthorizePermission;
use App\Modules\Identity\Presentation\CreateUserCommand;
use App\Modules\Leave\Domain\LeaveRefused;
use App\Modules\Leave\Domain\NoLeaveBalance;
use App\Modules\Leave\Presentation\AlertAnniversaryCommand;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([CreateUserCommand::class, AlertMissingDaysCommand::class, ImportMasterDataCommand::class, AlertAnniversaryCommand::class, PruneLogsCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not api-group: unmatched routes (404) never enter the api group.
        $middleware->prepend(AddRequestId::class);
        // Outermost (prepended last): its duration covers every other middleware, and any refusal they render is logged.
        $middleware->prepend(RecordRequest::class);
        $middleware->append(ForgetResolvedGuards::class);
        $middleware->api(prepend: [SetLocaleFromRequest::class]);
        // No login route to redirect to: an API guest gets the 401 envelope.
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
        $middleware->alias(['permission' => AuthorizePermission::class, 'employee.scope' => EnsureEmployeeInScope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every reported exception gets an error_log row; no ->stop(), so the default log still gets it too.
        $exceptions->report(fn (Throwable $e) => SystemLog::error($e));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (ValidationException $e, Request $request) => ApiEnvelope::error(
            $request, 422, 'validation_failed', __('errors.validation_failed'),
            collect($e->errors())->flatMap(fn (array $messages, string $field) => array_map(
                fn (string $m) => ['field' => $field, 'code' => 'invalid', 'message' => $m],
                $messages,
            ))->values()->all(),
        ));

        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => ApiEnvelope::error(
            $request, 404, 'not_found', __('errors.not_found'),
        ));

        $exceptions->render(fn (AuthenticationException $e, Request $request) => ApiEnvelope::error(
            $request, 401, 'unauthenticated', __('errors.unauthenticated'),
        ));

        // An expected answer, not a fault: logging it would also write the email, IP and user agent.
        $exceptions->dontReport(AuthenticationRefused::class);

        $exceptions->render(fn (AuthenticationRefused $e, Request $request) => ApiEnvelope::error(
            $request, $e->reason->status(), $e->reason->value, __('identity.refusal.'.$e->reason->value),
        ));

        // Same reasoning: a 403 is an answer, not a fault.
        $exceptions->dontReport(AuthorizationRefused::class);

        $exceptions->render(fn (AuthorizationRefused $e, Request $request) => ApiEnvelope::error(
            $request, 403, 'permission_denied', __('identity.refusal.permission_denied'),
        ));

        // An attendance refusal (locked period, future date) is an answer, not a fault.
        $exceptions->dontReport(AttendanceRefused::class);

        $exceptions->render(fn (AttendanceRefused $e, Request $request) => ApiEnvelope::error(
            $request, $e->reason->status(), $e->reason->value, __('attendance.refusal.'.$e->reason->value, $e->params), $e->details,
        ));

        $exceptions->dontReport([ConcurrencyConflict::class, NoLeaveBalance::class, LeaveRefused::class]);

        $exceptions->render(fn (ConcurrencyConflict $e, Request $request) => ApiEnvelope::error(
            $request, 409, ConcurrencyConflict::CODE, ConcurrencyConflict::message(), $e->details,
        ));

        $exceptions->render(fn (NoLeaveBalance $e, Request $request) => ApiEnvelope::error(
            $request, 422, 'no_leave_balance', __('errors.no_leave_balance'),
        ));

        $exceptions->render(fn (LeaveRefused $e, Request $request) => ApiEnvelope::error(
            $request, $e->reason->status(), $e->reason->value, __('leave.refusal.'.$e->reason->value, $e->params), extra: $e->dates === [] ? [] : ['dates' => $e->dates],
        ));

        $exceptions->dontReport([EmployeeCodeTaken::class, EmployeeArchived::class, EmployeeHasOpenItems::class]);

        $exceptions->render(fn (EmployeeHasOpenItems $e, Request $request) => ApiEnvelope::error(
            $request, 409, 'employee_has_open_items', __('errors.employee_has_open_items', ['count' => (string) count($e->items)]), extra: ['items' => $e->items],
        ));

        $exceptions->render(fn (EmployeeArchived $e, Request $request) => ApiEnvelope::error(
            $request, 409, 'employee_archived', __('errors.employee_archived'),
        ));

        $exceptions->render(fn (EmployeeCodeTaken $e, Request $request) => ApiEnvelope::error(
            $request, 409, 'code_taken', __('errors.code_taken', ['code' => $e->taken, 'name' => app()->getLocale() === 'en' && filled($e->nameEn) ? $e->nameEn : $e->nameAr]),
        ));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => ApiEnvelope::error(
            $request, 429, 'rate_limited', __('identity.refusal.rate_limited'),
        )->withHeaders($e->getHeaders()));

        // Thrown by global middleware before SetLocaleFromRequest: the message is in the default locale.
        $exceptions->render(fn (PostTooLargeException $e, Request $request) => ApiEnvelope::error(
            $request, 413, 'payload_too_large', __('errors.payload_too_large'),
        ));

        // Anything else on /api/*; HTTP exceptions and HttpResponseException keep their own response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiEnvelope::error(
                $request, 500, 'server_error',
                config('app.debug') ? $e->getMessage() : __('errors.server_error'),
            );
        });
    })->create();
