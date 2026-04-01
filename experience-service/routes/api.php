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
 *     GET  /api/school/courses                          — course catalogue (for experience creation)
 *
 * Middleware stack:
 *   - Health check endpoint: NO middleware (must be accessible for Docker health probes)
 *   - All other endpoints: 'auth.role' middleware (authentication + role enforcement) which:
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

use App\Http\Controllers\CourseController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\ExperienceScreenController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::prefix('school')->group(function () {
    // Health check — no auth required. Used by Docker HEALTHCHECK and load balancers.
    // Performs a real database connectivity check and pings the downstream Enrolment
    // Service. Returns "degraded" if downstream is unreachable, 503 if DB is down.
    Route::get('experiences/health', function () {
        $database = 'connected';
        $status = 'ok';
        $httpStatus = 200;
        $downstream = [];

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $database = 'unreachable';
            $status = 'error';
            $httpStatus = 503;
        }

        try {
            $response = Http::timeout(2)->get(config('services.enrolment.url') . '/api/school/enrolments/health');
            $downstream['enrolment-service'] = $response->successful() ? 'reachable' : 'unreachable';
        } catch (\Exception $e) {
            $downstream['enrolment-service'] = 'unreachable';
            if ($status === 'ok') {
                $status = 'degraded';
            }
        }

        return response()->json([
            'status' => $status,
            'service' => 'experience',
            'timestamp' => now()->toIso8601String(),
            'database' => $database,
            'downstream' => $downstream,
        ], $httpStatus);
    });

    // All routes inside this group require a valid bearer token and an authorized role.
    // The middleware alias 'auth.role' is registered in bootstrap/app.php (backed by
    // MockAuthMiddleware or HttpAuthMiddleware depending on the AUTH_MODE env var).

    // Read-only endpoints — accessible by admins, teachers, students, and parents.
    // Students see experience list, detail, contents, and their own student detail.
    // These MUST be registered before the write endpoints to prevent the {id} wildcard
    // from swallowing path segments like "students" or "contents" as an experience ID.
    Route::middleware('auth.role:student,parent')->group(function () {
        Route::get('experiences/{id}/students/{studentId}', [ExperienceScreenController::class, 'studentDetail'])->where(['id' => '[0-9]+', 'studentId' => '[0-9]+']);
        Route::get('experiences/{id}/students', [ExperienceScreenController::class, 'students'])->where('id', '[0-9]+');
        Route::get('experiences/{id}/contents', [ExperienceScreenController::class, 'contents'])->where('id', '[0-9]+');

        // Screen 301 read-only routes (list + detail)
        Route::get('experiences', [ExperienceController::class, 'index']);
        Route::get('experiences/{id}', [ExperienceController::class, 'show'])->where('id', '[0-9]+');
    });

    // Admin/teacher-only read endpoints — school-wide statistics, CSV exports, and course catalogue.
    // Students must not access school-wide aggregation or bulk data downloads.
    Route::middleware('auth.role')->group(function () {
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('experiences/{id}/students/export', [ExperienceScreenController::class, 'exportStudents'])->where('id', '[0-9]+');
        Route::get('experiences/{id}/statistics', [ExperienceScreenController::class, 'statistics'])->where('id', '[0-9]+');
    });

    // Write endpoints — admin and teacher (per workpack, teachers build experiences).
    Route::middleware('auth.role')->group(function () {
        Route::post('experiences', [ExperienceController::class, 'store']);
        Route::put('experiences/{id}', [ExperienceController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('experiences/{id}', [ExperienceController::class, 'destroy'])->where('id', '[0-9]+');
    });
});
