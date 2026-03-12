<?php

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Contracts\StudentProgressProviderInterface;
use Illuminate\Support\Facades\Http;

/**
 * Cohort summary widget for the School Admin Dashboard.
 *
 * Aggregates cohort counts (active, completed, upcoming) and enrolment
 * statistics from the Enrolment Service, plus credit progress and timely
 * completion metrics from the progress provider. Degrades gracefully when
 * the downstream Enrolment Service is unavailable.
 */
class CohortSummaryWidget implements DashboardWidget
{
    private string $token;
    private int $schoolId;
    private string $schoolName;
    private array $experiences;
    private StudentProgressProviderInterface $progressProvider;

    public function __construct(array $context)
    {
        $this->token = $context['token'];
        $this->schoolId = $context['school_id'];
        $this->schoolName = $context['school_name'];
        $this->experiences = $context['experiences'] ?? [];
        $this->progressProvider = $context['progress_provider'];
    }

    public function getData(): array
    {
        $cohortCounts = $this->fetchCohortCounts();
        $enrolmentStats = $this->fetchEnrolmentStatistics();
        $warnings = $enrolmentStats['_warnings'] ?? [];

        $totalEnrolled = $enrolmentStats['enrolled'] ?? 0;
        $assigned = $enrolmentStats['assigned'] ?? 0;
        $totalStudents = $enrolmentStats['total_students'] ?? 0;

        $activeExperiences = array_filter(
            $this->experiences,
            fn(array $e): bool => ($e['status'] ?? '') === 'active'
        );

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
                'credit_progress' => $this->progressProvider->calculateCreditProgress($this->experiences),
                'timely_completion' => $this->progressProvider->calculateTimelyCompletion($totalEnrolled, $assigned),
                'problems_tackled' => $this->progressProvider->countProblemsTackled($this->experiences),
                'active_ventures' => count($activeExperiences),
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
     * Embeds any warnings from the enrolment response into the _warnings key
     * so CohortSummaryWidget::getData() can surface them.
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
                $data['_warnings'] = $data['warnings'] ?? [];
                return $data;
            }
        } catch (\Exception $e) {
            // Degraded — return defaults
        }

        $defaults['_warnings'][] = [
            'type' => 'service_degraded',
            'message' => 'Enrolment service is unavailable',
            'severity' => 'warning',
        ];

        return $defaults;
    }
}
