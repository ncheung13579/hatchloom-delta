<?php

/**
 * DashboardService — Core aggregation logic for Screen 300 (School Admin Dashboard).
 *
 * Architecture role:
 *   This is the heart of the Dashboard Service. It owns NO database tables.
 *   Instead, it acts as an aggregation layer that:
 *   1. Calls the Experience Service (port 8002) for experience/course data
 *   2. Calls the Enrolment Service (port 8003) for cohort and enrolment data
 *   3. Delegates to injected Strategy providers for credential and progress data
 *   4. Merges everything into unified JSON responses for the frontend
 *
 * Design patterns used:
 *   - Repository: This service is the repository boundary between the controller
 *     and all data sources (HTTP services + provider interfaces)
 *   - Strategy: The credentialProvider and progressProvider are injected via
 *     interfaces, allowing mock/real implementations to be swapped in AppServiceProvider
 *   - Factory Method: Widget-related methods delegate to DashboardWidgetFactory
 *
 * Graceful degradation:
 *   Every HTTP call to a downstream service is wrapped in try/catch with a 5-second
 *   timeout. If a service is down, the response still succeeds (HTTP 200) with
 *   zero/empty fallback values and a 'service_degraded' warning in the warnings array.
 *   This ensures the frontend can render a partial dashboard rather than showing
 *   a full error page.
 *
 * Cross-service HTTP calls:
 *   All HTTP calls forward the caller's bearer token so that downstream services
 *   can authenticate and enforce school scoping. The service URLs are configured
 *   in config/services.php (services.experience.url, services.enrolment.url).
 *
 * @see \App\Http\Controllers\DashboardController  The thin controller that delegates here
 * @see \App\Factories\DashboardWidgetFactory       Factory for widget instantiation
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;
use App\Contracts\DashboardWidget;
use App\Contracts\StudentProgressProviderInterface;
use App\Factories\DashboardWidgetFactory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class DashboardService
{
    /**
     * All three dependencies are injected by Laravel's service container.
     *
     * - $credentialProvider: Resolves to MockCredentialDataProvider in D1 (Strategy pattern)
     * - $progressProvider: Resolves to MockStudentProgressProvider in D1 (Strategy pattern)
     * - $widgetFactory: Singleton instance that maps type strings to widget classes
     */
    public function __construct(
        private readonly CredentialDataProviderInterface $credentialProvider,
        private readonly StudentProgressProviderInterface $progressProvider,
        private readonly DashboardWidgetFactory $widgetFactory
    ) {}

    /**
     * Build the full dashboard overview for the authenticated school admin.
     *
     * This is the primary method powering GET /api/school/dashboard.
     * It makes three sequential HTTP calls to downstream services, collecting
     * warnings for any that fail. The response always succeeds (200) even if
     * one or both services are down — missing sections fall back to zero/empty
     * values so the frontend can render a partial dashboard.
     *
     * Response structure:
     *   school     — The authenticated user's school identity
     *   summary    — Key performance indicators (problems, ventures, credits, etc.)
     *   cohorts    — Breakdown of cohort statuses (active, completed, upcoming)
     *   students   — Student count breakdown (enrolled, assigned, unassigned)
     *   statistics — Computed rates (enrolment rate, completion, credit progress)
     *   warnings   — Any service degradation or data quality warnings
     */
    public function getDashboardOverview(): array
    {
        $user = Auth::user();
        $school = $user->school;
        // Forward the caller's token to downstream services so they can
        // authenticate the request and enforce school-scoped data access
        $token = request()->bearerToken();

        $warnings = [];
        $experienceData = null;
        $enrolmentStats = null;

        // --- HTTP Call #1: Experience Service (port 8002) ---
        // Fetches the list of experiences belonging to this school.
        // Expected response: { "data": [ { "id": 1, "title": "...", "status": "active", ... }, ... ] }
        // On failure: experienceData stays null, a warning is added, and experience-dependent
        // fields in the response will show zero/empty values.
        try {
            $experienceResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.experience.url') . '/api/school/experiences');

            if ($experienceResponse->successful()) {
                $experienceData = $experienceResponse->json();
            } else {
                $warnings[] = [
                    'type' => 'service_degraded',
                    'message' => 'Experience service is unavailable',
                    'severity' => 'warning',
                ];
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'type' => 'service_degraded',
                'message' => 'Experience service is unavailable',
                'severity' => 'warning',
            ];
        }

        // --- HTTP Call #2: Enrolment Service (port 8003) — statistics endpoint ---
        // Fetches aggregate enrolment statistics for this school.
        // Expected response: { "enrolled": 25, "assigned": 20, "not_assigned": 5,
        //                       "total_students": 30, "warnings": [...] }
        // On failure: enrolment numbers default to zero in the response.
        try {
            $enrolmentResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments/statistics');

            if ($enrolmentResponse->successful()) {
                $enrolmentStats = $enrolmentResponse->json();
            } else {
                $warnings[] = [
                    'type' => 'service_degraded',
                    'message' => 'Enrolment service is unavailable',
                    'severity' => 'warning',
                ];
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'type' => 'service_degraded',
                'message' => 'Enrolment service is unavailable',
                'severity' => 'warning',
            ];
        }

        // --- HTTP Call #3: Enrolment Service (port 8003) — cohort list endpoint ---
        // Fetches all cohorts to compute status counts (active, completed, upcoming).
        // This is a separate call from the statistics endpoint because the statistics
        // endpoint returns student-level aggregates, not cohort-level breakdowns.
        // Expected response: { "data": [ { "id": 1, "status": "active", ... }, ... ] }
        // On failure: all cohort counts remain zero.
        $cohortCounts = ['active' => 0, 'completed' => 0, 'upcoming' => 0, 'total' => 0];
        try {
            $cohortsResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts');

            if ($cohortsResponse->successful()) {
                $cohorts = collect($cohortsResponse->json('data', []));
                $cohortCounts = [
                    'active' => $cohorts->where('status', 'active')->count(),
                    'completed' => $cohorts->where('status', 'completed')->count(),
                    // 'not_started' is the Enrolment Service's status value for upcoming cohorts
                    'upcoming' => $cohorts->where('status', 'not_started')->count(),
                    'total' => $cohorts->count(),
                ];
            }
        } catch (\Exception $e) {
            // Degraded — cohort counts stay at zero
        }

        // The enrolment statistics endpoint may include its own warnings (e.g.,
        // students enrolled but not assigned to any cohort). Merge them into our
        // top-level warnings array so the frontend sees all issues in one place.
        if ($enrolmentStats && isset($enrolmentStats['warnings'])) {
            $warnings = array_merge($warnings, $enrolmentStats['warnings']);
        }

        // Extract values with null-coalescing defaults so the response structure
        // is always consistent, even when a downstream service is unavailable
        $totalEnrolled = $enrolmentStats['enrolled'] ?? 0;
        $assigned = $enrolmentStats['assigned'] ?? 0;
        $notAssigned = $enrolmentStats['not_assigned'] ?? 0;
        $totalStudents = $enrolmentStats['total_students'] ?? 0;

        $experiences = $experienceData['data'] ?? [];
        $experienceCount = count($experiences);
        $activeExperiences = array_filter($experiences, fn($e) => ($e['status'] ?? '') === 'active');

        return [
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
            ],
            'summary' => [
                // These delegate to the injected progress provider (mock in D1)
                'problems_tackled' => $this->progressProvider->countProblemsTackled($experiences),
                'active_ventures' => count($activeExperiences),
                'students' => $totalStudents,
                'experiences' => $experienceCount,
                'credit_progress' => $this->progressProvider->calculateCreditProgress($experiences),
                'timely_completion' => $this->progressProvider->calculateTimelyCompletion($totalEnrolled, $assigned),
            ],
            'cohorts' => $cohortCounts,
            'students' => [
                'total_enrolled' => $totalEnrolled,
                'active_in_cohorts' => $assigned,
                'not_assigned' => $notAssigned,
            ],
            'statistics' => [
                // Enrolment rate = fraction of all students who are enrolled in at least one cohort
                'enrolment_rate' => $totalStudents > 0 ? round($totalEnrolled / $totalStudents, 2) : 0,
                // These are placeholders for D1 — will be computed from real data in D2
                'average_completion' => 0.0,
                'average_credit_progress' => 0.0,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Fetch detailed data for a single student (drill-down from the dashboard).
     *
     * Called when an admin clicks a student row on Screen 300. Verifies the
     * student belongs to the caller's school (security: school scoping), then
     * enriches with enrolment data from the Enrolment Service and credential/
     * curriculum data from the injected providers.
     *
     * @param  int        $studentId  The user ID of the student to look up
     * @return array|null             Student detail data, or null if not found/not in caller's school
     */
    public function getStudentDrillDown(int $studentId): ?array
    {
        $user = Auth::user();
        $token = request()->bearerToken();

        // Security: query scoped to the caller's school_id AND role='student'.
        // This prevents admins from one school seeing students from another school,
        // and prevents looking up non-student users via this endpoint.
        $student = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return null;
        }

        $enrolments = [];

        // --- HTTP Call: Enrolment Service (port 8003) — student-specific enrolments ---
        // Fetches all cohort enrolments for this specific student.
        // Expected response: { "enrolments": [ { "cohort_id": 1, "status": "enrolled", ... }, ... ] }
        // On failure: student data is returned without enrolment information.
        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments/students/' . $studentId);

            if ($response->successful()) {
                $enrolments = $response->json('enrolments', []);
            }
        } catch (\Exception $e) {
            // Degraded response — no enrolment data, but student info still returned
        }

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'enrolments' => $enrolments,
            // Progress values are hardcoded placeholders for D1.
            // In D2, these will come from Team Papa's Course Service.
            'progress' => [
                'courses_completed' => 1,
                'courses_in_progress' => 2,
                'overall_completion' => 0.35,
            ],
            // Credentials and curriculum mapping come from the injected provider
            // (MockCredentialDataProvider in D1, Karl's credential engine post-D1)
            'credentials' => $this->credentialProvider->getStudentCredentials($studentId),
            'curriculum_mapping' => $this->credentialProvider->getStudentCurriculumMapping($studentId),
        ];
    }

    /**
     * R3 Reporting: Per-student PoS curriculum coverage across the school.
     *
     * Returns each student's coverage percentage for the three Alberta Program
     * of Studies areas: Business Studies, CTF Design Studies, and CALM (Career
     * and Life Management). Also includes school-wide averages.
     *
     * All students are queried with school_id scoping for multi-tenant isolation.
     */
    public function getPosCoverage(): array
    {
        $user = Auth::user();
        // Query all students in the caller's school — school_id scoping
        // ensures we never leak data from other schools
        $students = User::where('school_id', $user->school_id)
            ->where('role', 'student')
            ->get();

        $progressData = $this->progressProvider->getPosCoverage($students);

        return [
            'school_id' => $user->school_id,
            // The three Alberta PoS areas that Hatchloom experiences map to
            'pos_areas' => ['Business Studies', 'CTF Design Studies', 'CALM'],
            'student_coverage' => $progressData['student_coverage'],
            'school_averages' => $progressData['school_averages'],
        ];
    }

    /**
     * R3 Reporting: Engagement rates across the school.
     *
     * Returns per-student metrics (login frequency, activity completion, last
     * active timestamp) and school-wide averages for the last 30 days.
     */
    public function getEngagementRates(): array
    {
        $user = Auth::user();
        $students = User::where('school_id', $user->school_id)
            ->where('role', 'student')
            ->get();

        $engagementData = $this->progressProvider->getEngagementRates($students);

        return [
            'school_id' => $user->school_id,
            'period' => 'last_30_days',
            'school_averages' => $engagementData['school_averages'],
            'student_engagement' => $engagementData['student_engagement'],
        ];
    }

    /**
     * Build a single dashboard widget by type using the Factory Method pattern.
     *
     * Constructs a shared context array from the authenticated user's school
     * and current request, then delegates to DashboardWidgetFactory::create()
     * which returns the appropriate DashboardWidget implementation.
     *
     * @throws \InvalidArgumentException  If the widget type is not registered in the factory
     */
    public function getWidget(string $type): array
    {
        $widget = $this->buildWidget($type);

        return [
            'type' => $widget->getType(),
            'data' => $widget->getData(),
        ];
    }

    /**
     * Build all registered dashboard widgets and return their data.
     *
     * Powers GET /api/school/dashboard/widgets — returns every widget in a
     * single response. This is more efficient for initial page load than
     * making N separate requests, one per widget type.
     */
    public function getAllWidgets(): array
    {
        $types = $this->widgetFactory->getAvailableTypes();
        $widgets = [];

        foreach ($types as $type) {
            $widget = $this->buildWidget($type);
            $widgets[] = [
                'type' => $widget->getType(),
                'data' => $widget->getData(),
            ];
        }

        return ['widgets' => $widgets];
    }

    /**
     * Instantiate a DashboardWidget via the factory with the shared context.
     *
     * The context array carries everything widgets might need:
     *   - token: Bearer token to forward to downstream HTTP calls
     *   - school_id / school_name: For school-scoped queries within widgets
     *   - experiences: Pre-fetched from the Experience Service (avoids duplicate HTTP calls)
     *   - progress_provider / credential_provider: Injected Strategy implementations
     *
     * Experience data is pre-fetched once here rather than letting each widget
     * fetch it independently, which would cause redundant HTTP calls when
     * multiple widgets need the same experience list.
     */
    private function buildWidget(string $type): DashboardWidget
    {
        $user = Auth::user();
        $school = $user->school;
        $token = request()->bearerToken();

        // Pre-fetch experience data so widgets that need it don't each make
        // their own HTTP call to the Experience Service
        $experiences = $this->fetchExperiences($token);

        $context = [
            'token' => $token,
            'school_id' => $school->id,
            'school_name' => $school->name,
            'experiences' => $experiences,
            'progress_provider' => $this->progressProvider,
            'credential_provider' => $this->credentialProvider,
        ];

        return $this->widgetFactory->create($type, $context);
    }

    /**
     * Fetch experience data from the Experience Service (port 8002).
     *
     * Calls GET /api/school/experiences on the Experience Service.
     * Expected response: { "data": [ { "id": 1, "title": "...", ... }, ... ] }
     * On failure: returns an empty array (graceful degradation).
     *
     * This is a shared helper used by buildWidget() so multiple widgets can
     * reuse the same experience list without each one making a separate HTTP call.
     *
     * @return array<int, array<string, mixed>>  List of experience records, or empty on failure
     */
    private function fetchExperiences(string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.experience.url') . '/api/school/experiences');

            if ($response->successful()) {
                return $response->json('data', []);
            }
        } catch (\Exception $e) {
            // Degraded — return empty array so widgets can still render
            // with zero experience data rather than crashing
        }

        return [];
    }
}
