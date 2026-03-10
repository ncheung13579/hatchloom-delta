<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contract for accessing student progress, engagement, and PoS coverage data.
 *
 * In D1, fulfilled by MockStudentProgressProvider with deterministic sample data.
 * When Team Papa's Course Service and Karl's credential engine are integrated,
 * a real implementation will derive these metrics from actual course completions
 * and credential awards.
 */
interface StudentProgressProviderInterface
{
    /** Count problems/challenges tackled across experiences. */
    public function countProblemsTackled(array $experiences): int;

    /** Aggregate credit progress across all experiences. */
    public function calculateCreditProgress(array $experiences): float;

    /** Calculate timely completion rate for enrolled students. */
    public function calculateTimelyCompletion(int $totalEnrolled, int $assigned): float;

    /** Build per-student PoS curriculum coverage data. */
    public function getPosCoverage(Collection $students): array;

    /** Build per-student engagement metrics. */
    public function getEngagementRates(Collection $students): array;
}
