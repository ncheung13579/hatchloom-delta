<?php

/**
 * MockLaunchPadDataProvider — Static sample data for LaunchPad/SideHustle APIs.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the mock implementation of LaunchPadDataProviderInterface.
 *   It returns hardcoded sample data that demonstrates the expected response
 *   structure without requiring Quebec's LaunchPad Service to exist.
 *
 * Important characteristics:
 *   - Returns fixed counts regardless of school/student ID. This is intentional
 *     for mock providers — the mock shows the correct structure.
 *   - Venture data represents SideHustle sandbox projects where students build
 *     and run simulated businesses.
 *
 * How to replace with real data (when real services are integrated):
 *   1. Create a new class (e.g., HttpLaunchPadDataProvider) implementing
 *      LaunchPadDataProviderInterface that calls Quebec's LaunchPad API
 *   2. Update the binding in AppServiceProvider::register() to point to the
 *      new class instead of this mock
 *   3. No changes needed in DashboardService or any controller
 *
 * @see \App\Contracts\LaunchPadDataProviderInterface  The interface this implements
 * @see \App\Providers\AppServiceProvider              Where the DI binding is configured
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LaunchPadDataProviderInterface;

class MockLaunchPadDataProvider implements LaunchPadDataProviderInterface
{
    /**
     * Return a mock count of active ventures for a school.
     *
     * In production, this would call Quebec's LaunchPad API filtered by school_id
     * and count ventures with an 'active' status. The mock returns a fixed count
     * of 7 to represent a realistic school with several active SideHustle projects.
     */
    public function countActiveVentures(int $schoolId): int
    {
        return 7;
    }

    /**
     * Return sample venture data for a student.
     *
     * In production, this would call Quebec's LaunchPad API filtered by student_id
     * to retrieve their SideHustle ventures. Each venture has an id, name, status,
     * and created_at timestamp.
     */
    public function getStudentVentures(int $studentId): array
    {
        return [
            'active' => 1,
            'completed' => 1,
            'ventures' => [
                [
                    'id' => 1,
                    'name' => 'Campus Snack Box',
                    'status' => 'active',
                    'created_at' => '2026-02-01T00:00:00Z',
                ],
                [
                    'id' => 2,
                    'name' => 'Study Buddy Tutoring',
                    'status' => 'completed',
                    'created_at' => '2026-01-15T00:00:00Z',
                ],
            ],
        ];
    }
}
