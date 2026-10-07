<?php

use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Runs first in the api group so every response below — including
        // validation and the exception renders further down — is already in
        // the right locale. Per-request only (see the middleware's own
        // docblock): nothing here is persisted to session or anywhere else.
        $middleware->prependToGroup('api', SetLocaleFromAcceptLanguage::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => __('errors.unauthenticated'),
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => __('errors.validation_error'),
                        'fields' => $e->errors(),
                    ],
                ], $e->status);
            }
        });

        // Laravel rewraps a failed implicit route-model binding
        // (ModelNotFoundException) as a NotFoundHttpException before any
        // custom renderer for the original exception runs, so this is
        // registered for the wrapper, not ModelNotFoundException itself.
        // It also covers a genuinely unmatched route, which is the same
        // "not found" semantics from the client's point of view.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => __('errors.not_found'),
                    ],
                ], 404);
            }
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'SESSION_EXPIRED',
                        'message' => __('errors.session_expired'),
                    ],
                ], 419);
            }
        });
    })->create();
