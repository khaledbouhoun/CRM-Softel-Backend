<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\BusinessRuleException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Customer portal API (separate from the LAN-only staff API).
            Route::group([], base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every /api response is JSON in the standard envelope, including errors.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return app(ApiExceptionRenderer::class)->render($e, $request);
            }

            return null;
        });

        // Business rule and validation failures are expected; keep them out of the error log.
        $exceptions->dontReport([BusinessRuleException::class]);
    })->create();
