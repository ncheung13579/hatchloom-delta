<?php

/**
 * DashboardController — Thin HTTP controller for Screen 300 (School Admin Dashboard).
 *
 * Architecture role:
 *   This is the single entry point for all Dashboard Service HTTP endpoints.
 *   It follows the Controller -> Service -> Model pattern mandated by our
 *   architecture: controllers validate input and return responses, but ALL
 *   business logic lives in DashboardService.
 *
 * How it fits into the Dashboard Service:
 *   The Dashboard Service is an aggregation layer that owns no database tables.
 *   This controller exposes REST endpoints that the frontend (Screen 300) calls.
 *   Under the hood, DashboardService makes HTTP calls to the Experience Service
 *   (port 8002) and Enrolment Service (port 8003) to assemble the response.
 *
 * Authentication:
 *   Every endpoint in this controller sits behind the 'auth.role' middleware
 *   (see routes/api.php), which requires a valid bearer token and restricts
 *   access to school_admin and school_teacher roles.
 *
 * @see \App\Services\DashboardService  The service that does the real work
 * @see routes/api.php                  Route definitions and middleware stack
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * DashboardService is injected via Laravel's service container (constructor injection).
     * The container resolves it automatically, including its own dependencies
     * (CredentialDataProviderInterface, StudentProgressProviderInterface, DashboardWidgetFactory).
     */
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /**
     * GET /api/school/dashboard
     *
     * Return the aggregated dashboard overview for the caller's school.
     * This is the primary endpoint that powers the main view of Screen 300.
     * It merges data from the Experience Service and Enrolment Service into
     * a single JSON response with school info, summary statistics, cohort
     * counts, student counts, and any service-degradation warnings.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->getDashboardOverview());
    }

    /**
     * GET /api/school/dashboard/students/{studentId}
     *
     * Return detailed drill-down data for a single student. Called when an
     * admin clicks on a student row in the dashboard to see their enrolments,
     * progress, credentials, and curriculum mapping.
     *
     * The service enforces school scoping — a student from another school will
     * return null, which we translate to a 404 error response.
     */
    public function studentDrillDown(int $studentId): JsonResponse
    {
        // Students can only view their own drill-down; parents can view their linked children's.
        $user = Auth::user();
        if ($user->role === 'student' && $user->id !== $studentId) {
            return response()->json([
                'error' => true,
                'message' => 'Forbidden',
                'code' => 'FORBIDDEN',
            ], 403);
        }
        if ($user->role === 'parent' && !$this->parentCanAccessStudent($user->id, $studentId)) {
            return response()->json([
                'error' => true,
                'message' => 'Forbidden',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $result = $this->dashboardService->getStudentDrillDown($studentId);

        if (!$result) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($result);
    }

    /**
     * GET /api/school/dashboard/reporting/pos-coverage
     *
     * Return Alberta Program of Studies (PoS) curriculum coverage data.
     * This is an R3 (Requirement 3) reporting endpoint that shows how much
     * of the three PoS areas (Business Studies, CTF Design Studies, CALM)
     * each student has covered through their Hatchloom experiences.
     */
    public function posCoverage(): JsonResponse
    {
        return response()->json($this->dashboardService->getPosCoverage());
    }

    /**
     * GET /api/school/dashboard/reporting/engagement
     *
     * Return student engagement rates for the last 30 days (R3 reporting).
     * Provides per-student login frequency, activity completion rates, and
     * school-wide averages for the engagement chart on Screen 300.
     */
    public function engagement(): JsonResponse
    {
        return response()->json($this->dashboardService->getEngagementRates());
    }

    /**
     * GET /api/school/dashboard/widgets
     *
     * Return all dashboard widgets in a single response. Uses the Factory
     * Method pattern via DashboardWidgetFactory to build each registered
     * widget type and collect their data payloads. This is the preferred
     * endpoint for initial page load — the frontend gets everything at once
     * instead of making separate calls per widget.
     */
    public function widgets(): JsonResponse
    {
        return response()->json($this->dashboardService->getAllWidgets());
    }

    /**
     * GET /api/school/dashboard/widgets/{type}
     *
     * Return a single dashboard widget by type. Accepts the widget type as
     * a URL segment (e.g. cohort_summary, student_table, engagement_chart).
     * Useful for refreshing one section of the dashboard without reloading
     * everything.
     *
     * If the type is not recognized by the factory, DashboardWidgetFactory
     * throws InvalidArgumentException, which we catch and return as a 422.
     */
    public function widget(string $type): JsonResponse
    {
        try {
            return response()->json($this->dashboardService->getWidget($type));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }
    }

    /**
     * Check whether a parent has a link to the given student.
     *
     * Queries the parent_student_links table, which is the canonical
     * many-to-many relationship between parents and their children.
     */
    private function parentCanAccessStudent(int $parentId, int $studentId): bool
    {
        return DB::table('parent_student_links')
            ->where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->exists();
    }
}
