<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller for Screen 300 (School Admin Dashboard).
 *
 * Validates input and delegates all business logic to DashboardService.
 * Every endpoint requires an authenticated school_admin or school_teacher.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    /** Return the aggregated dashboard overview for the caller's school. */
    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->getDashboardOverview());
    }

    /** Return detailed drill-down data for a single student. */
    public function studentDrillDown(int $studentId): JsonResponse
    {
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

    /** Return Alberta PoS curriculum coverage data (R3 reporting). */
    public function posCoverage(): JsonResponse
    {
        return response()->json($this->dashboardService->getPosCoverage());
    }

    /** Return student engagement rates for the last 30 days (R3 reporting). */
    public function engagement(): JsonResponse
    {
        return response()->json($this->dashboardService->getEngagementRates());
    }

    /**
     * Return all dashboard widgets in a single response.
     *
     * Uses the Factory Method pattern via DashboardWidgetFactory to build
     * each widget type and collect their data payloads.
     */
    public function widgets(): JsonResponse
    {
        return response()->json($this->dashboardService->getAllWidgets());
    }

    /**
     * Return a single dashboard widget by type.
     *
     * Accepts the widget type as a URL segment (e.g. cohort_summary,
     * student_table, engagement_chart). Returns 422 if the type is unknown.
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
}
