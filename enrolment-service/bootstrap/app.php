<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'mock.auth' => \App\Http\Middleware\MockAuthMiddleware::class,
        ]);
        $middleware->appendToGroup('api', [
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
    })->create();
