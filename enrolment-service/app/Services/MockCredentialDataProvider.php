<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;

/**
 * Mock credential data for D1 testing in the Enrolment Service.
 *
 * Returns empty credential summaries. When Karl's credential engine is
 * available, replace this binding in AppServiceProvider with a real
 * implementation that queries actual credential data.
 */
class MockCredentialDataProvider implements CredentialDataProviderInterface
{
    public function getStudentCredentialSummary(int $studentId): array
    {
        return [
            'total_earned' => 0,
            'in_progress' => 0,
            'details' => [],
        ];
    }
}
