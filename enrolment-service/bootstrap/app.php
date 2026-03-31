<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $authMiddleware = env('AUTH_MODE', 'http') === 'http'
            ? \App\Http\Middleware\HttpAuthMiddleware::class
            : \App\Http\Middleware\MockAuthMiddleware::class;

        // 'auth.role' is the single alias for authentication + role enforcement.
        // AUTH_MODE (env) controls which implementation backs it:
        //   - 'http' (default): HttpAuthMiddleware — validates bearer tokens via Team Quebec's auth service
        //   - 'mock':           MockAuthMiddleware — uses hardcoded token map for local dev/testing
        $middleware->alias([
            'auth.role' => $authMiddleware,
        ]);
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
            \App\Http\Middleware\AuditLogMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Normalize Laravel validation errors into the standard Delta error envelope
        // so all API consumers see one consistent format: {error, message, code, errors}.
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => $e->errors(),
            ], $e->status);
        });

        // Route not found (e.g., GET /api/school/nonexistent)
        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'error' => true,
                'message' => 'The requested resource was not found',
                'code' => 'NOT_FOUND',
            ], 404);
        });

        // Wrong HTTP method (e.g., POST to a GET-only route)
        $exceptions->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            return response()->json([
                'error' => true,
                'message' => 'HTTP method not allowed',
                'code' => 'METHOD_NOT_ALLOWED',
            ], 405);
        });

        // Catch-all for unhandled exceptions — log and return a generic 500
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            Log::error('Unhandled exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'uri' => $request->getRequestUri(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'An internal server error occurred',
                'code' => 'SERVER_ERROR',
            ], 500);
        });
    })->create();
