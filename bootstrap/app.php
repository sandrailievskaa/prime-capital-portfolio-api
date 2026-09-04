<?php

use App\Exceptions\DomainRuleException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Machine-readable error_code envelope, same shape as
        // CreateTransactionRequest::failedValidation() — no per-field
        // `errors` here since this is a whole-request business decision,
        // not a field-validation failure. Registered against the base
        // class so InsufficientHoldingsException (Phase 5) and any future
        // DomainRuleException subclass is handled automatically — no
        // second render() registration needed.
        $exceptions->render(function (DomainRuleException $e, Request $request) {
            return response()->json([
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 422);
        });

        // Route-model-binding misses (e.g. POST /clients/999999/...) throw
        // ModelNotFoundException, which Laravel's handler converts to this
        // class before dispatching to render() — without this, APP_DEBUG=true
        // leaks a full stack trace (file paths included) into the JSON body
        // even though the status code is already correctly 404.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'error_code' => 'not_found',
                'message' => $e->getMessage(),
            ], 404);
        });
    })->create();
