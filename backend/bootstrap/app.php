<?php

use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\ForgetResolvedGuards;
use App\Modules\Identity\Domain\Authentication\AuthenticationRefused;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
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
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not api-group: unmatched routes (404) never enter the api group.
        $middleware->prepend(AddRequestId::class);
        $middleware->append(ForgetResolvedGuards::class);
        // No login route to redirect to: an API guest gets the 401 envelope.
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (ValidationException $e, Request $request) => ApiEnvelope::error(
            $request, 422, 'validation_failed', 'The given data was invalid.',
            collect($e->errors())->flatMap(fn (array $messages, string $field) => array_map(
                fn (string $m) => ['field' => $field, 'code' => 'invalid', 'message' => $m],
                $messages,
            ))->values()->all(),
        ));

        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => ApiEnvelope::error(
            $request, 404, 'not_found', 'Resource not found.',
        ));

        $exceptions->render(fn (AuthenticationException $e, Request $request) => ApiEnvelope::error(
            $request, 401, 'unauthenticated', 'Unauthenticated.',
        ));

        // An expected answer, not a fault: logging it would also write the email, IP and user agent.
        $exceptions->dontReport(AuthenticationRefused::class);

        $exceptions->render(fn (AuthenticationRefused $e, Request $request) => ApiEnvelope::error(
            $request, $e->reason->status(), $e->reason->value, __('identity.refusal.'.$e->reason->value),
        ));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => ApiEnvelope::error(
            $request, 429, 'rate_limited', __('identity.refusal.rate_limited'),
        )->withHeaders($e->getHeaders()));

        // Anything else on /api/*; HTTP exceptions and HttpResponseException keep their own response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiEnvelope::error(
                $request, 500, 'server_error',
                config('app.debug') ? $e->getMessage() : 'Server error.',
            );
        });
    })->create();
