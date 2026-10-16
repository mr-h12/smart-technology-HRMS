<?php

use App\Http\Middleware\AddRequestId;
use App\Support\Http\ApiEnvelope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $middleware->api(prepend: [AddRequestId::class]);
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

        // Anything else on /api/*; HttpExceptions (405, 401...) keep their own status via Laravel's default handling.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return ApiEnvelope::error(
                $request, 500, 'server_error',
                config('app.debug') ? $e->getMessage() : 'Server error.',
            );
        });
    })->create();
