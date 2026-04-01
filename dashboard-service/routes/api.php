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
 *   AUTHENTICATED — admin only (auth.role):
 *     GET /api/school/dashboard                          — Full dashboard overview
 *     GET /api/school/dashboard/reporting/pos-coverage    — R3: PoS curriculum coverage
 *     GET /api/school/dashboard/reporting/engagement      — R3: Engagement rates
 *     GET /api/school/dashboard/widgets                   — All widgets (Factory Method)
 *     GET /api/school/dashboard/widgets/{type}            — Single widget by type
 *
 *   AUTHENTICATED — all roles (auth.role:school_teacher,student,parent):
 *     GET /api/school/dashboard/students/{studentId}     — Student drill-down (scoped by controller)
 *
 * Middleware stack for authenticated routes:
 *   1. 'auth.role' (MockAuthMiddleware) — Validates bearer token, resolves user,
 *      checks role (school_admin only by default). Registered in bootstrap/app.php.
 *   2. AuditLogMiddleware may also be in the global middleware stack for mutation logging.
 *
 * Note: All endpoints are GET (read-only). The Dashboard Service is an aggregation
 * layer that never mutates data. Write operations happen in the Experience Service
 * and Enrolment Service.
 */

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// All dashboard routes live under /api/school/ to match the URL convention
// used across all three Delta microservices
Route::prefix('school')->group(function () {

    // Health check endpoint — no authentication required.
    // Performs a real database connectivity check and pings downstream services.
    // Returns "degraded" if any downstream is unreachable, 503 if DB is down.
    Route::get('dashboard/health', function () {
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

        $services = [
            'experience-service' => config('services.experience.url') . '/api/school/experiences/health',
            'enrolment-service' => config('services.enrolment.url') . '/api/school/enrolments/health',
        ];

        foreach ($services as $name => $url) {
            try {
                $response = Http::timeout(2)->get($url);
                $downstream[$name] = $response->successful() ? 'reachable' : 'unreachable';
            } catch (\Exception $e) {
                $downstream[$name] = 'unreachable';
                if ($status === 'ok') {
                    $status = 'degraded';
                }
            }
        }

        return response()->json([
            'status' => $status,
            'service' => 'dashboard',
            'timestamp' => now()->toIso8601String(),
            'database' => $database,
            'downstream' => $downstream,
        ], $httpStatus);
    });

    // School-wide dashboard endpoints — admin only.
    // These are the school admin overview screens (Screen 300): aggregated data
    // across all students/cohorts. Teachers have their own per-cohort views
    // via the Experience and Enrolment services.
    Route::middleware('auth.role')->group(function () {
        // Main dashboard overview — aggregates Experience + Enrolment service data
        Route::get('dashboard', [DashboardController::class, 'index']);

        // R3 reporting endpoints — Alberta PoS coverage and engagement metrics
        Route::get('dashboard/reporting/pos-coverage', [DashboardController::class, 'posCoverage']);
        Route::get('dashboard/reporting/engagement', [DashboardController::class, 'engagement']);

        // Widget endpoints — Factory Method pattern for modular dashboard sections
        Route::get('dashboard/widgets', [DashboardController::class, 'widgets']);
        // {type} is one of: cohort_summary, student_table, engagement_chart
        Route::get('dashboard/widgets/{type}', [DashboardController::class, 'widget']);
    });

    // Student drill-down — accessible by all authenticated roles.
    // Teachers can drill down into their students; students see their own data;
    // parents see linked children. Controller enforces scoping.
    Route::middleware('auth.role:school_teacher,student,parent')->group(function () {
        Route::get('dashboard/students/{studentId}', [DashboardController::class, 'studentDrillDown']);
    });
});
