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

        // Same {error_code, message} shape as StoreTransactionRequest's
        // failedValidation(), minus `errors` — this is a whole-request
        // business decision, not a field-validation failure. Registered
        // against the base class, so any DomainRuleException subclass is
        // handled automatically with no second render() registration.
        $exceptions->render(function (DomainRuleException $e, Request $request) {
            return response()->json([
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], 422);
        });

        // Without this, APP_DEBUG=true leaks a full stack trace into the
        // JSON body on a route-model-binding miss, even though the status
        // code is already correctly 404.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'error_code' => 'not_found',
                'message' => $e->getMessage(),
            ], 404);
        });
    })->create();
