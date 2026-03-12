<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;
use App\Contracts\DashboardWidget;
use App\Contracts\StudentProgressProviderInterface;
use App\Factories\DashboardWidgetFactory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Aggregation layer for Screen 300 (School Admin Dashboard).
 *
 * This service owns no database tables. It calls the Experience Service and
 * Enrolment Service over HTTP, merges their responses into a single dashboard
 * payload, and degrades gracefully when either downstream service is unavailable.
 * R3 reporting methods (PoS coverage, engagement rates) delegate to injected
 * provider interfaces that can be swapped from mock to real implementations
 * via the service container binding in AppServiceProvider.
 */
class DashboardService
{
    public function __construct(
        private readonly CredentialDataProviderInterface $credentialProvider,
        private readonly StudentProgressProviderInterface $progressProvider,
        private readonly DashboardWidgetFactory $widgetFactory
    ) {}

    /**
     * Build the full dashboard overview for the authenticated school admin.
     *
     * Calls both downstream services in sequence, collecting warnings for any
     * that fail. The response always succeeds (200) even if one or both services
     * are down — missing sections fall back to zero/empty values so the frontend
     * can render a partial dashboard.
     */
    public function getDashboardOverview(): array
    {
        $user = Auth::user();
        $school = $user->school;
        $token = request()->bearerToken();

        $warnings = [];
        $experienceData = null;
        $enrolmentStats = null;

        // Call Experience Service
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

        // Call Enrolment Service — statistics
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

        // Call Enrolment Service — cohort counts for the dashboard overview
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
                    'upcoming' => $cohorts->where('status', 'not_started')->count(),
                    'total' => $cohorts->count(),
                ];
            }
        } catch (\Exception $e) {
            // Degraded — cohort counts stay at zero
        }

        // Merge warnings from enrolment stats
        if ($enrolmentStats && isset($enrolmentStats['warnings'])) {
            $warnings = array_merge($warnings, $enrolmentStats['warnings']);
        }

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
                'enrolment_rate' => $totalStudents > 0 ? round($totalEnrolled / $totalStudents, 2) : 0,
                'average_completion' => 0.0,
                'average_credit_progress' => 0.0,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Fetch detailed data for a single student (drill-down from the dashboard).
     *
     * Verifies the student belongs to the caller's school, then enriches with
     * enrolment data from the Enrolment Service and credential/curriculum data
     * from the injected providers. Returns null if the student is not found or
     * not in the caller's school.
     */
    public function getStudentDrillDown(int $studentId): ?array
    {
        $user = Auth::user();
        $token = request()->bearerToken();

        $student = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return null;
        }

        $enrolments = [];

        // Try to get enrolment data from Enrolment Service
        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments', [
                    'search' => $student->name,
                ]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                foreach ($data as $item) {
                    if (($item['student_id'] ?? null) == $studentId) {
                        $enrolments = $item['cohort_assignments'] ?? [];
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Degraded response — no enrolment data
        }

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'enrolments' => $enrolments,
            'progress' => [
                'courses_completed' => 1,
                'courses_in_progress' => 2,
                'overall_completion' => 0.35,
            ],
            'credentials' => $this->credentialProvider->getStudentCredentials($studentId),
            'curriculum_mapping' => $this->credentialProvider->getStudentCurriculumMapping($studentId),
        ];
    }

    /**
     * R3: Per-student PoS curriculum coverage across the school.
     * Returns each student's coverage percentage for the three Alberta PoS areas.
     */
    public function getPosCoverage(): array
    {
        $user = Auth::user();
        $students = User::where('school_id', $user->school_id)
            ->where('role', 'student')
            ->get();

        $progressData = $this->progressProvider->getPosCoverage($students);

        return [
            'school_id' => $user->school_id,
            'pos_areas' => ['Business Studies', 'CTF Design Studies', 'CALM'],
            'student_coverage' => $progressData['student_coverage'],
            'school_averages' => $progressData['school_averages'],
        ];
    }

    /**
     * R3: Engagement rates across the school — per-cohort and per-student metrics.
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
     * This powers a "full widgets" endpoint that returns every widget in a
     * single response, keyed by widget type. Useful for initial dashboard
     * load where the frontend needs all sections at once.
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
     * The context array carries everything widgets need: the bearer token for
     * downstream HTTP calls, the school identity for scoping, experience data
     * fetched from the Experience Service, and the injected provider instances.
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
     * Fetch experience data from the Experience Service.
     *
     * Shared helper so multiple widgets can reuse the same experience list
     * without each one making a separate HTTP call.
     *
     * @return array<int, array<string, mixed>>
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
            // Degraded — return empty array
        }

        return [];
    }
}
