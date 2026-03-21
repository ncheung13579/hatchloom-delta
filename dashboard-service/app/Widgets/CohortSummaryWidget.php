<?php

/**
 * CohortSummaryWidget — Cohort counts and enrolment statistics widget.
 *
 * Design pattern: Factory Method (concrete product)
 *   This is one of the concrete DashboardWidget implementations created by
 *   DashboardWidgetFactory when the type is 'cohort_summary'. It provides
 *   the top-level summary card on Screen 300 showing cohort status breakdown,
 *   student enrollment numbers, and key performance metrics.
 *
 * Data sources:
 *   - Enrolment Service (port 8003): Cohort list and enrolment statistics
 *   - Experience data: Passed in via context (pre-fetched by DashboardService)
 *   - Progress provider: Injected Strategy for credit and completion metrics
 *
 * Cross-service HTTP calls:
 *   This widget makes TWO HTTP calls to the Enrolment Service:
 *   1. GET /api/school/cohorts — for cohort status counts
 *   2. GET /api/school/enrolments/statistics — for enrolment aggregates
 *   Both calls use a 5-second timeout and degrade gracefully (return zeros)
 *   if the Enrolment Service is unavailable.
 *
 * @see \App\Contracts\DashboardWidget          Interface this implements
 * @see \App\Factories\DashboardWidgetFactory   Factory that instantiates this
 */

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Contracts\LaunchPadDataProviderInterface;
use App\Contracts\StudentProgressProviderInterface;
use Illuminate\Support\Facades\Http;

class CohortSummaryWidget implements DashboardWidget
{
    private string $token;
    private int $schoolId;
    private string $schoolName;
    private array $experiences;
    private StudentProgressProviderInterface $progressProvider;
    private LaunchPadDataProviderInterface $launchPadProvider;

    /**
     * Widgets receive a context array (not individual constructor params) because
     * the factory creates them dynamically and different widget types need different
     * subsets of context data. This avoids a rigid constructor signature.
     */
    public function __construct(array $context)
    {
        $this->token = $context['token'];
        $this->schoolId = $context['school_id'];
        $this->schoolName = $context['school_name'];
        // Experiences are pre-fetched by DashboardService::buildWidget() to avoid
        // duplicate HTTP calls when multiple widgets need the same data
        $this->experiences = $context['experiences'] ?? [];
        $this->progressProvider = $context['progress_provider'];
        $this->launchPadProvider = $context['launchpad_provider'];
    }

    public function getData(): array
    {
        // Make two HTTP calls to the Enrolment Service for cohort and enrolment data
        $cohortCounts = $this->fetchCohortCounts();
        $enrolmentStats = $this->fetchEnrolmentStatistics();
        // '_warnings' is an internal key added by fetchEnrolmentStatistics() to carry
        // warnings from the Enrolment Service response up to the widget output
        $warnings = $enrolmentStats['_warnings'] ?? [];

        $totalEnrolled = $enrolmentStats['enrolled'] ?? 0;
        $assigned = $enrolmentStats['assigned'] ?? 0;
        $totalStudents = $enrolmentStats['total_students'] ?? 0;

        return [
            'school' => [
                'id' => $this->schoolId,
                'name' => $this->schoolName,
            ],
            'cohorts' => $cohortCounts,
            'students' => [
                'total_enrolled' => $totalEnrolled,
                'active_in_cohorts' => $assigned,
                'not_assigned' => $enrolmentStats['not_assigned'] ?? 0,
            ],
            'statistics' => [
                'enrolment_rate' => $totalStudents > 0 ? round($totalEnrolled / $totalStudents, 2) : 0,
                // These delegate to the Strategy provider (mock currently, real when services are integrated)
                'credit_progress' => $this->progressProvider->calculateCreditProgress($this->experiences),
                'timely_completion' => $this->progressProvider->calculateTimelyCompletion($totalEnrolled, $assigned),
                'problems_tackled' => $this->progressProvider->countProblemsTackled($this->experiences),
                'active_ventures' => $this->launchPadProvider->countActiveVentures($this->schoolId),
            ],
            'warnings' => $warnings,
        ];
    }

    public function getType(): string
    {
        return 'cohort_summary';
    }

    /**
     * Fetch cohort status counts from the Enrolment Service.
     *
     * Calls: GET {enrolment_service}/api/school/cohorts
     * Expected response: { "data": [ { "id": 1, "status": "active", ... }, ... ] }
     *
     * The Enrolment Service uses three cohort statuses:
     *   - 'active'      — Cohort is currently running
     *   - 'completed'   — Cohort has finished
     *   - 'not_started' — Cohort hasn't begun yet (mapped to 'upcoming' in output)
     *
     * On failure: returns all-zero counts rather than throwing an exception.
     */
    private function fetchCohortCounts(): array
    {
        $defaults = ['active' => 0, 'completed' => 0, 'upcoming' => 0, 'total' => 0];

        try {
            $response = Http::withToken($this->token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts');

            if ($response->successful()) {
                $cohorts = collect($response->json('data', []));
                return [
                    'active' => $cohorts->where('status', 'active')->count(),
                    'completed' => $cohorts->where('status', 'completed')->count(),
                    'upcoming' => $cohorts->where('status', 'not_started')->count(),
                    'total' => $cohorts->count(),
                ];
            }
        } catch (\Exception $e) {
            // Degraded — return zeroed counts
        }

        return $defaults;
    }

    /**
     * Fetch enrolment statistics from the Enrolment Service.
     *
     * Calls: GET {enrolment_service}/api/school/enrolments/statistics
     * Expected response: { "enrolled": 25, "assigned": 20, "not_assigned": 5,
     *                       "total_students": 30, "warnings": [...] }
     *
     * The response may include warnings from the Enrolment Service itself (e.g.,
     * "3 students enrolled but not assigned to any cohort"). These are embedded
     * into a '_warnings' key so getData() can surface them in the widget output.
     *
     * On failure: returns zeroed defaults AND adds a service_degraded warning
     * so the frontend knows the data may be incomplete.
     */
    private function fetchEnrolmentStatistics(): array
    {
        $defaults = [
            'enrolled' => 0,
            'assigned' => 0,
            'not_assigned' => 0,
            'total_students' => 0,
            '_warnings' => [],
        ];

        try {
            $response = Http::withToken($this->token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments/statistics');

            if ($response->successful()) {
                $data = $response->json();
                // Rename 'warnings' to '_warnings' to avoid key collision with
                // other fields when this data is merged into the widget response
                $data['_warnings'] = $data['warnings'] ?? [];
                return $data;
            }
        } catch (\Exception $e) {
            // Degraded — fall through to return defaults with a warning
        }

        // When the Enrolment Service is unreachable, add a degradation warning
        // so the frontend can show a "data may be incomplete" banner
        $defaults['_warnings'][] = [
            'type' => 'service_degraded',
            'message' => 'Enrolment service is unavailable',
            'severity' => 'warning',
        ];

        return $defaults;
    }
}
