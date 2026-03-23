<?php

declare(strict_types=1);

/**
 * API Routes — Enrolment Service (port 8003).
 *
 * Defines all REST endpoints for the Enrolment Service, which owns Screen 303
 * (Enrolment) and cohort management. All routes are prefixed with /school and
 * grouped by authentication requirement.
 *
 * ROUTE STRUCTURE:
 *
 *   /school/enrolments/health          (public — no auth)
 *     GET: Health check endpoint for Docker/load balancer probes
 *
 *   /school/cohorts                    (auth required — mock.auth middleware)
 *     GET:    List cohorts (CohortController@index)
 *     POST:   Create cohort (CohortController@store)
 *
 *   /school/cohorts/{id}              (auth required)
 *     GET:    Show cohort detail (CohortController@show)
 *     PUT:    Update cohort (CohortController@update)
 *     (DELETE is excluded — cohorts are never deleted, only completed)
 *
 *   /school/cohorts/{id}/activate     (auth required)
 *     PATCH:  Transition cohort to active status (State pattern)
 *
 *   /school/cohorts/{id}/complete     (auth required)
 *     PATCH:  Transition cohort to completed status (State pattern, terminal)
 *
 *   /school/cohorts/{cohortId}/enrolments              (auth required)
 *     POST:   Enrol a student (EnrolmentController@enrol)
 *
 *   /school/cohorts/{cohortId}/enrolments/{studentId}  (auth required)
 *     DELETE:  Remove a student (soft-delete via EnrolmentController@remove)
 *
 *   /school/enrolments                (auth required)
 *     GET:    Paginated student overview (EnrolmentController@index)
 *
 *   /school/enrolments/statistics     (auth required)
 *     GET:    Aggregate statistics with warnings (EnrolmentController@statistics)
 *
 *   /school/enrolments/students/{studentId}  (auth required)
 *     GET:    Student detail drill-down (EnrolmentController@studentDetail)
 *
 *   /school/enrolments/export         (auth required)
 *     GET:    CSV download of all enrolments (EnrolmentController@export)
 *
 * MIDDLEWARE:
 *  - 'mock.auth': MockAuthMiddleware — validates bearer token, sets Auth::user(),
 *    and checks role. Applied to all routes except the health check.
 *  - AuditLogMiddleware is registered globally (not per-route) and logs all
 *    mutating requests (POST, PUT, PATCH, DELETE).
 *
 * @see \App\Http\Controllers\CohortController     Cohort CRUD + state transitions
 * @see \App\Http\Controllers\EnrolmentController   Student enrolment operations
 * @see \App\Http\Middleware\MockAuthMiddleware      Authentication middleware
 */

use App\Http\Controllers\CohortController;
use App\Http\Controllers\EnrolmentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// All routes are prefixed with /school to namespace the Enrolment Service's
// API under the school administration context.
Route::prefix('school')->group(function () {

    // Health check — intentionally outside the auth middleware so Docker
    // health probes and load balancers can reach it without a token.
    // Performs a real database connectivity check and returns 503 if unreachable.
    Route::get('enrolments/health', function () {
        $database = 'connected';
        $status = 'ok';
        $httpStatus = 200;

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $database = 'unreachable';
            $status = 'error';
            $httpStatus = 503;
        }

        return response()->json([
            'status' => $status,
            'service' => 'enrolment',
            'timestamp' => now()->toIso8601String(),
            'database' => $database,
        ], $httpStatus);
    });

    // Read-only endpoints — accessible by admins, teachers, students, and parents.
    // Students see only their own data (auto-filtered by EnrolmentService).
    // Parents see only their linked child's data.
    Route::middleware('mock.auth:student,parent')->group(function () {
        Route::get('cohorts', [CohortController::class, 'index']);
        Route::get('cohorts/{id}', [CohortController::class, 'show'])->where('id', '[0-9]+');

        Route::get('enrolments', [EnrolmentController::class, 'index']);
        Route::get('enrolments/students/{studentId}', [EnrolmentController::class, 'studentDetail'])->where('studentId', '[0-9]+');
    });

    // Admin/teacher-only read endpoints — school-wide data and exports.
    Route::middleware('mock.auth')->group(function () {
        Route::get('enrolments/statistics', [EnrolmentController::class, 'statistics']);
        Route::get('enrolments/export', [EnrolmentController::class, 'export']);
    });

    // Write endpoints — admin and teacher only (no student access).
    Route::middleware('mock.auth')->group(function () {
        Route::post('cohorts', [CohortController::class, 'store']);
        Route::put('cohorts/{id}', [CohortController::class, 'update'])->where('id', '[0-9]+');

        // State transition endpoints — PATCH is used (not PUT) because these
        // are partial updates that change only the status field.
        Route::patch('cohorts/{id}/activate', [CohortController::class, 'activate'])->where('id', '[0-9]+');
        Route::patch('cohorts/{id}/complete', [CohortController::class, 'complete'])->where('id', '[0-9]+');

        // Enrolment operations — nested under cohorts because enrolments belong
        // to a specific cohort.
        Route::post('cohorts/{cohortId}/enrolments', [EnrolmentController::class, 'enrol'])->where('cohortId', '[0-9]+');
        Route::delete('cohorts/{cohortId}/enrolments/{studentId}', [EnrolmentController::class, 'remove'])->where(['cohortId' => '[0-9]+', 'studentId' => '[0-9]+']);
    });
});
