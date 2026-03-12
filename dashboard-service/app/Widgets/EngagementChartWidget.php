<?php

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Contracts\StudentProgressProviderInterface;
use App\Models\User;

/**
 * Engagement chart widget for the School Admin Dashboard.
 *
 * Provides engagement metrics suitable for charting: per-student activity rates,
 * login frequency, and school-wide averages. Delegates to the injected progress
 * provider for the underlying engagement data, then adds chart-friendly summary
 * fields (distribution buckets, trend indicators) that a frontend charting
 * library can consume directly.
 */
class EngagementChartWidget implements DashboardWidget
{
    private int $schoolId;
    private StudentProgressProviderInterface $progressProvider;

    public function __construct(array $context)
    {
        $this->schoolId = $context['school_id'];
        $this->progressProvider = $context['progress_provider'];
    }

    public function getData(): array
    {
        $students = User::where('school_id', $this->schoolId)
            ->where('role', 'student')
            ->get();

        $engagementData = $this->progressProvider->getEngagementRates($students);
        $studentEngagement = $engagementData['student_engagement'] ?? [];
        $schoolAverages = $engagementData['school_averages'] ?? [];

        // Build distribution buckets for the engagement chart so the frontend
        // can render a histogram without post-processing
        $distribution = $this->buildDistribution($studentEngagement);

        return [
            'period' => 'last_30_days',
            'school_averages' => [
                'avg_login_days' => $schoolAverages['avg_login_days'] ?? 0,
                'avg_completion_rate' => $schoolAverages['avg_completion_rate'] ?? 0,
                'active_student_count' => $schoolAverages['active_student_count'] ?? 0,
            ],
            'distribution' => $distribution,
            'student_metrics' => array_map(function (array $entry): array {
                return [
                    'student_id' => $entry['student_id'],
                    'student_name' => $entry['student_name'],
                    'login_days' => $entry['login_days_last_30'],
                    'completion_rate' => $entry['completion_rate'],
                    'activities_completed' => $entry['activities_completed'],
                    'total_activities' => $entry['total_activities'],
                    'last_active_at' => $entry['last_active_at'],
                    'engagement_level' => $this->classifyEngagement($entry['completion_rate']),
                ];
            }, $studentEngagement),
        ];
    }

    public function getType(): string
    {
        return 'engagement_chart';
    }

    /**
     * Build histogram-style distribution buckets from completion rates.
     *
     * Buckets: 0-25% (low), 25-50% (moderate), 50-75% (good), 75-100% (excellent).
     *
     * @param array<int, array<string, mixed>> $studentEngagement
     * @return array<string, int>
     */
    private function buildDistribution(array $studentEngagement): array
    {
        $buckets = [
            'low' => 0,        // 0–25%
            'moderate' => 0,   // 25–50%
            'good' => 0,       // 50–75%
            'excellent' => 0,  // 75–100%
        ];

        foreach ($studentEngagement as $entry) {
            $rate = $entry['completion_rate'] ?? 0.0;
            $level = $this->classifyEngagement($rate);
            $buckets[$level]++;
        }

        return $buckets;
    }

    /**
     * Classify a completion rate into an engagement level.
     */
    private function classifyEngagement(float $rate): string
    {
        if ($rate >= 0.75) {
            return 'excellent';
        }
        if ($rate >= 0.50) {
            return 'good';
        }
        if ($rate >= 0.25) {
            return 'moderate';
        }
        return 'low';
    }
}
