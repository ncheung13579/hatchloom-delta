<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StudentProgressProviderInterface;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mock student progress data for D1 testing.
 *
 * Returns deterministic sample data for problems tackled, credit progress,
 * timely completion, PoS coverage, and engagement rates. When Team Papa's
 * Course Service is integrated, replace this binding in AppServiceProvider
 * with the real implementation that derives metrics from actual completions.
 */
class MockStudentProgressProvider implements StudentProgressProviderInterface
{
    public function countProblemsTackled(array $experiences): int
    {
        $active = array_filter($experiences, fn($e) => ($e['status'] ?? '') === 'active');
        return count($active) * 3;
    }

    public function calculateCreditProgress(array $experiences): float
    {
        if (empty($experiences)) {
            return 0.0;
        }
        return 0.45;
    }

    public function calculateTimelyCompletion(int $totalEnrolled, int $assigned): float
    {
        if ($totalEnrolled === 0) {
            return 0.0;
        }
        return 0.72;
    }

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

    public function getEngagementRates(Collection $students): array
    {
        $studentEngagement = $students->map(function (User $student) {
            $loginDays = rand(5, 20);
            $activitiesCompleted = rand(3, 30);
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
