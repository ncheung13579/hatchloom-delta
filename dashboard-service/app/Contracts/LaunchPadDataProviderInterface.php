<?php

/**
 * LaunchPadDataProviderInterface — Strategy pattern contract for LaunchPad data.
 *
 * Design pattern: Strategy
 *   This interface defines the Strategy contract for accessing venture/SideHustle
 *   data from Quebec's LaunchPad Service. The DashboardService depends on this
 *   interface (not a concrete class), allowing the implementation to be swapped
 *   without changing any service or controller code.
 *
 * Current binding (mock):
 *   AppServiceProvider binds this to MockLaunchPadDataProvider, which returns
 *   hardcoded sample data representing active SideHustle sandbox projects.
 *
 * Future binding (when real services are integrated):
 *   When Team Quebec delivers the LaunchPad Service, a real implementation
 *   (e.g., HttpLaunchPadDataProvider) will query Quebec's API for live venture
 *   data. The only change needed is updating the binding in AppServiceProvider.
 *
 * Where it's used:
 *   - DashboardService::getDashboardOverview() — active_ventures count for the
 *     dashboard summary KPI card (Screen 300)
 *   - CohortSummaryWidget — active_ventures in the cohort summary widget
 *
 * @see \App\Services\MockLaunchPadDataProvider  Current mock implementation
 * @see \App\Providers\AppServiceProvider        Where the binding is configured
 */

declare(strict_types=1);

namespace App\Contracts;

interface LaunchPadDataProviderInterface
{
    /**
     * Count active ventures (SideHustle sandbox projects) for a given school.
     *
     * A "venture" is a student's SideHustle business simulation in Quebec's
     * LaunchPad module. This count powers the active_ventures KPI on the
     * School Admin Dashboard (Screen 300).
     *
     * @param  int $schoolId  The school to count ventures for
     * @return int  Number of active ventures
     */
    public function countActiveVentures(int $schoolId): int;

    /**
     * Get venture summary data for a specific student.
     *
     * Returns an overview of a student's LaunchPad activity, including their
     * active ventures and completion status. Used for the student drill-down
     * view on the dashboard.
     *
     * @param  int $studentId  The user ID of the student
     * @return array{active: int, completed: int, ventures: array<int, array<string, mixed>>}
     */
    public function getStudentVentures(int $studentId): array;
}
