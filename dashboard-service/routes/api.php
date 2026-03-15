<?php

/**
 * API Route Definitions for the Dashboard Service.
 *
 * All routes are prefixed with /api (from RouteServiceProvider) + /school,
 * giving a base URL of /api/school/dashboard/...
 *
 * Route structure:
 *   PUBLIC (no auth):
 *     GET /api/school/dashboard/health — Health check for Docker/load balancer
 *
 *   AUTHENTICATED (mock.auth middleware):
 *     GET /api/school/dashboard                          — Full dashboard overview
 *     GET /api/school/dashboard/students/{studentId}     — Student drill-down
 *     GET /api/school/dashboard/reporting/pos-coverage    — R3: PoS curriculum coverage
 *     GET /api/school/dashboard/reporting/engagement      — R3: Engagement rates
 *     GET /api/school/dashboard/widgets                   — All widgets (Factory Method)
 *     GET /api/school/dashboard/widgets/{type}            — Single widget by type
 *
 * Middleware stack for authenticated routes:
 *   1. 'mock.auth' (MockAuthMiddleware) — Validates bearer token, resolves user,
 *      checks role (school_admin or school_teacher). Registered in bootstrap/app.php.
 *   2. AuditLogMiddleware may also be in the global middleware stack for mutation logging.
 *
 * Note: All endpoints are GET (read-only). The Dashboard Service is an aggregation
 * layer that never mutates data. Write operations happen in the Experience Service
 * and Enrolment Service.
 */

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// All dashboard routes live under /api/school/ to match the URL convention
// used across all three Delta microservices
Route::prefix('school')->group(function () {

    // Health check endpoint — no authentication required.
    // Used by Docker health checks and the other microservices to verify
    // this service is running. Returns a simple JSON with status and timestamp.
    Route::get('dashboard/health', fn() => response()->json([
        'status' => 'ok',
        'service' => 'dashboard',
        'timestamp' => now()->toIso8601String(),
    ]));

    // All remaining routes require authentication via MockAuthMiddleware.
    // The middleware resolves the bearer token to a User model and enforces
    // that the user has the school_admin or school_teacher role.
    Route::middleware('mock.auth')->group(function () {
        // Main dashboard overview — aggregates Experience + Enrolment service data
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Student drill-down — detailed view for a single student by user ID
        Route::get('dashboard/students/{studentId}', [DashboardController::class, 'studentDrillDown']);

        // R3 reporting endpoints — Alberta PoS coverage and engagement metrics
        Route::get('dashboard/reporting/pos-coverage', [DashboardController::class, 'posCoverage']);
        Route::get('dashboard/reporting/engagement', [DashboardController::class, 'engagement']);

        // Widget endpoints — Factory Method pattern for modular dashboard sections
        Route::get('dashboard/widgets', [DashboardController::class, 'widgets']);
        // {type} is one of: cohort_summary, student_table, engagement_chart
        Route::get('dashboard/widgets/{type}', [DashboardController::class, 'widget']);
    });
});
