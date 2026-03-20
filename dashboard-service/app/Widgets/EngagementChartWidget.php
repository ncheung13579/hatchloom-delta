<?php

/**
 * EngagementChartWidget — Engagement metrics formatted for chart rendering.
 *
 * Design pattern: Factory Method (concrete product)
 *   This is a concrete DashboardWidget created by DashboardWidgetFactory when
 *   the type is 'engagement_chart'. It provides data specifically structured
 *   for rendering engagement charts on Screen 300.
 *
 * Data sources:
 *   - Local DB: Queries the users table for students in the school
 *   - Progress provider (Strategy): Injected via context for engagement metrics
 *
 * Key difference from the /reporting/engagement endpoint:
 *   The reporting endpoint (DashboardService::getEngagementRates) returns raw
 *   engagement data. This widget adds chart-specific post-processing:
 *   - Distribution buckets (histogram data for low/moderate/good/excellent)
 *   - Per-student engagement_level classification
 *   - Reformatted field names that match the frontend chart component's API
 *
 * No cross-service HTTP calls:
 *   Unlike CohortSummaryWidget and StudentTableWidget, this widget does NOT
 *   call any downstream services directly. It relies entirely on the injected
 *   progress provider and the local users table.
 *
 * @see \App\Contracts\DashboardWidget          Interface this implements
 * @see \App\Factories\DashboardWidgetFactory   Factory that instantiates this
 */

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Contracts\StudentProgressProviderInterface;
use App\Models\User;

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
        // Query students from the local DB with school_id scoping
        $students = User::where('school_id', $this->schoolId)
            ->where('role', 'student')
            ->get();

        // Convert Eloquent models to plain arrays — keeps the interface
        // framework-agnostic so external teams can implement it via HTTP.
        $studentData = $students->map(fn(User $s) => ['id' => $s->id, 'name' => $s->name])->values()->toArray();

        // Delegate to the Strategy provider for raw engagement data
        $engagementData = $this->progressProvider->getEngagementRates($studentData);
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
            // Reformat per-student data with chart-friendly field names and add
            // a classified engagement_level for color-coding in the frontend
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
     * Counts how many students fall into each engagement tier. The frontend
     * can use these counts directly to render a bar chart or donut chart
     * showing the school's engagement distribution at a glance.
     *
     * Buckets:
     *   low       — 0-25% completion rate (at risk, needs intervention)
     *   moderate  — 25-50% completion rate (below average)
     *   good      — 50-75% completion rate (on track)
     *   excellent — 75-100% completion rate (exceeding expectations)
     *
     * @param array<int, array<string, mixed>> $studentEngagement
     * @return array<string, int>  Bucket name -> student count
     */
    private function buildDistribution(array $studentEngagement): array
    {
        $buckets = [
            'low' => 0,
            'moderate' => 0,
            'good' => 0,
            'excellent' => 0,
        ];

        foreach ($studentEngagement as $entry) {
            $rate = $entry['completion_rate'] ?? 0.0;
            $level = $this->classifyEngagement($rate);
            $buckets[$level]++;
        }

        return $buckets;
    }

    /**
     * Classify a completion rate into a named engagement level.
     *
     * Thresholds are aligned with the distribution buckets above.
     * The same classification is used for both the distribution histogram
     * and the per-student engagement_level field in student_metrics.
     *
     * @param  float  $rate  Completion rate between 0.0 and 1.0
     * @return string  One of: 'low', 'moderate', 'good', 'excellent'
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
