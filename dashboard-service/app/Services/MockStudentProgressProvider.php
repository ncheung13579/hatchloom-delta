<?php

/**
 * MockStudentProgressProvider — Sample data for student progress and engagement metrics.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the D1 concrete Strategy for StudentProgressProviderInterface.
 *   It returns a mix of deterministic and randomized sample data to simulate
 *   realistic student progress metrics without requiring real course completion
 *   data from Team Papa's Course Service or Karl's credential engine.
 *
 * Data characteristics:
 *   - countProblemsTackled: Semi-deterministic — scales with active experience count
 *   - calculateCreditProgress: Fixed at 0.45 (45%) when experiences exist
 *   - calculateTimelyCompletion: Fixed at 0.72 (72%) when students are enrolled
 *   - getPosCoverage: Uses rand() for per-student variation in demo data
 *   - getEngagementRates: Uses rand() for per-student variation in demo data
 *
 * Note on rand() usage:
 *   The PoS coverage and engagement methods use rand() to generate different
 *   values per student per request. This means responses are not deterministic
 *   across requests — acceptable for D1 demo purposes but would be an issue
 *   for automated testing. A real implementation would derive stable values
 *   from actual database records.
 *
 * How to replace with real data (post-D1):
 *   1. Create a new class implementing StudentProgressProviderInterface
 *   2. Query Team Papa's Course Service for completion data
 *   3. Query Karl's credential tables for credit and PoS coverage
 *   4. Query platform activity logs for engagement metrics
 *   5. Update the binding in AppServiceProvider::register()
 *
 * @see \App\Contracts\StudentProgressProviderInterface  The interface this implements
 * @see \App\Providers\AppServiceProvider                Where the DI binding is configured
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StudentProgressProviderInterface;
use App\Models\User;
use Illuminate\Support\Collection;

class MockStudentProgressProvider implements StudentProgressProviderInterface
{
    /**
     * Count problems/challenges tackled across experiences.
     *
     * Mock logic: assumes 3 problems per active experience. In production,
     * this would count actual challenge attempts from Team Papa's Course Service.
     */
    public function countProblemsTackled(array $experiences): int
    {
        $active = array_filter($experiences, fn($e) => ($e['status'] ?? '') === 'active');
        return count($active) * 3;
    }

    /**
     * Aggregate credit progress across all experiences.
     *
     * Returns a fixed 45% when experiences exist, 0% when empty. In production,
     * this would calculate the ratio of earned credits to total available credits
     * across all experiences the school's students are enrolled in.
     */
    public function calculateCreditProgress(array $experiences): float
    {
        if (empty($experiences)) {
            return 0.0;
        }
        return 0.45;
    }

    /**
     * Calculate timely completion rate for enrolled students.
     *
     * Returns a fixed 72% when students are enrolled, 0% when none. In production,
     * this would compare actual completion dates against cohort deadlines.
     */
    public function calculateTimelyCompletion(int $totalEnrolled, int $assigned): float
    {
        if ($totalEnrolled === 0) {
            return 0.0;
        }
        return 0.72;
    }

    /**
     * Build per-student PoS curriculum coverage data with randomized values.
     *
     * Generates a coverage entry for each student across the three Alberta PoS
     * areas. The 'total' values (8, 7, 5) match the real requirement counts
     * defined in the curriculum specification. The 'completed' and 'percentage'
     * values are randomized within plausible ranges to simulate variation.
     *
     * School averages use Collection::avg() with dot notation to compute means
     * across all students. The ?: fallback values (0.45, 0.38, 0.52) are used
     * when the collection is empty (no students) to avoid returning null.
     */
    public function getPosCoverage(Collection $students): array
    {
        $coverage = $students->map(function (User $student) {
            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'coverage' => [
                    'business_studies' => [
                        'completed' => rand(2, 6),
                        'total' => 8,
                        'percentage' => round(rand(25, 75) / 100, 2),
                    ],
                    'ctf_design_studies' => [
                        'completed' => rand(1, 5),
                        'total' => 7,
                        'percentage' => round(rand(15, 70) / 100, 2),
                    ],
                    'calm' => [
                        'completed' => rand(1, 4),
                        'total' => 5,
                        'percentage' => round(rand(20, 80) / 100, 2),
                    ],
                ],
                'overall_coverage' => round(rand(25, 70) / 100, 2),
            ];
        });

        return [
            'student_coverage' => $coverage->values()->toArray(),
            'school_averages' => [
                'business_studies' => round($coverage->avg('coverage.business_studies.percentage') ?: 0.45, 2),
                'ctf_design_studies' => round($coverage->avg('coverage.ctf_design_studies.percentage') ?: 0.38, 2),
                'calm' => round($coverage->avg('coverage.calm.percentage') ?: 0.52, 2),
            ],
        ];
    }

    /**
     * Build per-student engagement metrics with randomized values.
     *
     * Generates login frequency, activity completion, and last-active data per
     * student. Used by both the /reporting/engagement endpoint and the
     * EngagementChartWidget.
     *
     * The totalActivities is always >= activitiesCompleted to ensure the
     * completion_rate is between 0.0 and 1.0. last_active_at is randomized
     * within the last 7 days to simulate recent activity.
     */
    public function getEngagementRates(Collection $students): array
    {
        $studentEngagement = $students->map(function (User $student) {
            $loginDays = rand(5, 20);
            $activitiesCompleted = rand(3, 30);
            // Ensure total >= completed so completion_rate stays in [0, 1]
            $totalActivities = rand($activitiesCompleted, $activitiesCompleted + 15);

            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'login_days_last_30' => $loginDays,
                'activities_completed' => $activitiesCompleted,
                'total_activities' => $totalActivities,
                'completion_rate' => $totalActivities > 0
                    ? round($activitiesCompleted / $totalActivities, 2)
                    : 0.0,
                'last_active_at' => now()->subDays(rand(0, 7))->toIso8601String(),
            ];
        });

        return [
            'student_engagement' => $studentEngagement->values()->toArray(),
            'school_averages' => [
                'avg_login_days' => round($studentEngagement->avg('login_days_last_30') ?: 0, 1),
                'avg_completion_rate' => round($studentEngagement->avg('completion_rate') ?: 0, 2),
                'active_student_count' => $students->count(),
            ],
        ];
    }
}
