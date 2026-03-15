<?php

/**
 * StudentProgressProviderInterface — Strategy pattern contract for student metrics.
 *
 * Design pattern: Strategy
 *   Like CredentialDataProviderInterface, this is a Strategy contract that
 *   lets the DashboardService consume student progress data without knowing
 *   where it comes from. The implementation can be swapped via the DI binding
 *   in AppServiceProvider.
 *
 * Current binding (D1):
 *   AppServiceProvider binds this to MockStudentProgressProvider, which
 *   returns deterministic or randomized sample data.
 *
 * Future binding (post-D1):
 *   A real implementation will derive metrics from:
 *   - Team Papa's Course Service (course completion, challenge progress)
 *   - Karl's credential engine (credit progress, PoS coverage)
 *   - Platform activity logs (login frequency, engagement rates)
 *
 * Where it's used:
 *   - DashboardService::getDashboardOverview() — summary statistics
 *   - DashboardService::getPosCoverage() — R3 PoS reporting endpoint
 *   - DashboardService::getEngagementRates() — R3 engagement reporting endpoint
 *   - CohortSummaryWidget — cohort statistics section
 *   - EngagementChartWidget — engagement chart section
 *
 * @see \App\Services\MockStudentProgressProvider  Current D1 implementation
 * @see \App\Providers\AppServiceProvider           Where the binding is configured
 */

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

interface StudentProgressProviderInterface
{
    /**
     * Count problems/challenges tackled across experiences.
     *
     * Used in the dashboard summary to show how many challenges students are
     * engaging with. The experiences array comes from the Experience Service.
     *
     * @param  array<int, array<string, mixed>> $experiences  Experience records from Experience Service
     * @return int  Total problems/challenges tackled
     */
    public function countProblemsTackled(array $experiences): int;

    /**
     * Aggregate credit progress across all experiences.
     *
     * Returns a value between 0.0 and 1.0 representing overall credit
     * completion across all active experiences.
     *
     * @param  array<int, array<string, mixed>> $experiences  Experience records from Experience Service
     * @return float  Credit progress ratio (0.0 to 1.0)
     */
    public function calculateCreditProgress(array $experiences): float;

    /**
     * Calculate timely completion rate for enrolled students.
     *
     * Measures what fraction of enrolled students are completing activities
     * on time. Used in the dashboard summary statistics.
     *
     * @param  int   $totalEnrolled  Total students enrolled in any cohort
     * @param  int   $assigned       Students actively assigned to cohorts
     * @return float  Timely completion ratio (0.0 to 1.0)
     */
    public function calculateTimelyCompletion(int $totalEnrolled, int $assigned): float;

    /**
     * Build per-student PoS curriculum coverage data.
     *
     * Returns coverage for the three Alberta Program of Studies areas:
     * Business Studies, CTF Design Studies, and CALM. Used by the
     * /reporting/pos-coverage endpoint (R3 requirement).
     *
     * @param  Collection<int, User> $students  Students in the school
     * @return array{student_coverage: array, school_averages: array}
     */
    public function getPosCoverage(Collection $students): array;

    /**
     * Build per-student engagement metrics.
     *
     * Returns login frequency, activity completion rates, and last-active
     * timestamps per student, plus school-wide averages. Used by the
     * /reporting/engagement endpoint (R3 requirement) and EngagementChartWidget.
     *
     * @param  Collection<int, User> $students  Students in the school
     * @return array{student_engagement: array, school_averages: array}
     */
    public function getEngagementRates(Collection $students): array;
}
