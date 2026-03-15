<?php

/**
 * API Routes — Experience Service (port 8002).
 *
 * URL structure:
 *   All routes are prefixed with /api/school/ (the /api prefix comes from Laravel's
 *   RouteServiceProvider, the /school prefix is added here). This gives us URLs like:
 *     GET  /api/school/experiences           — list experiences (Screen 301)
 *     POST /api/school/experiences           — create experience
 *     GET  /api/school/experiences/{id}      — show experience detail
 *     PUT  /api/school/experiences/{id}      — update experience
 *     DELETE /api/school/experiences/{id}    — archive experience
 *     GET  /api/school/experiences/{id}/students       — student list (Screen 302)
 *     GET  /api/school/experiences/{id}/students/export — CSV download (Screen 302)
 *     GET  /api/school/experiences/{id}/students/{sid} — student detail (Screen 302)
 *     GET  /api/school/experiences/{id}/contents        — course blocks (Screen 302)
 *     GET  /api/school/experiences/{id}/statistics      — stats panel (Screen 302)
 *
 * Middleware stack:
 *   - Health check endpoint: NO middleware (must be accessible for Docker health probes)
 *   - All other endpoints: 'mock.auth' middleware (MockAuthMiddleware) which:
 *       1. Authenticates via bearer token -> user lookup
 *       2. Authorizes the user's role (school_admin or school_teacher only)
 *       3. Sets Auth::user() so SchoolScope can enforce tenant isolation
 *     The 'audit' middleware (AuditLogMiddleware) is applied globally via the kernel,
 *     so it does not need to be listed here.
 *
 * Route registration order matters:
 *   The Screen 302 sub-resource routes (students, contents, statistics) are registered
 *   BEFORE the apiResource() call. This is critical because apiResource generates a
 *   GET /experiences/{experience} route that would match /experiences/students if it
 *   came first (Laravel matches routes top-to-bottom, and {experience} is a wildcard).
 */

declare(strict_types=1);

use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\ExperienceScreenController;
use Illuminate\Support\Facades\Route;

Route::prefix('school')->group(function () {
    // Health check — no auth required. Used by Docker HEALTHCHECK and load balancers
    // to verify the service is running. Returns a simple JSON payload with the service
    // name and current timestamp.
    Route::get('experiences/health', fn() => response()->json([
        'status' => 'ok',
        'service' => 'experience',
        'timestamp' => now()->toIso8601String(),
    ]));

    // All routes inside this group require a valid bearer token (mock auth for D1).
    // The middleware alias 'mock.auth' is registered in the application's kernel/bootstrap.
    Route::middleware('mock.auth')->group(function () {
        // --- Screen 302 sub-resource routes ---
        // These MUST be registered before apiResource to prevent the {id} wildcard
        // from swallowing path segments like "students" or "contents" as an experience ID.
        Route::get('experiences/{id}/students/export', [ExperienceScreenController::class, 'exportStudents']);
        Route::get('experiences/{id}/students/{studentId}', [ExperienceScreenController::class, 'studentDetail']);
        Route::get('experiences/{id}/students', [ExperienceScreenController::class, 'students']);
        Route::get('experiences/{id}/contents', [ExperienceScreenController::class, 'contents']);
        Route::get('experiences/{id}/statistics', [ExperienceScreenController::class, 'statistics']);

        // --- Screen 301 CRUD routes ---
        // apiResource() generates: index, store, show, update, destroy
        // It does NOT generate create or edit (those are form-display routes for Blade,
        // which we don't use in an API-only service).
        Route::apiResource('experiences', ExperienceController::class);
    });
});
