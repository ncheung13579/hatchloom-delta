<?php

declare(strict_types=1);

/**
 * MockCredentialDataProvider — D1 stub for the Strategy pattern credential provider.
 *
 * This is the concrete implementation of CredentialDataProviderInterface for the
 * first deliverable (D1). It returns zeroed-out credential summaries because Karl's
 * credential engine (Role B) is not yet available.
 *
 * HOW THE STRATEGY PATTERN WORKS HERE:
 *  1. EnrolmentService depends on CredentialDataProviderInterface (not this class)
 *  2. AppServiceProvider binds the interface to this mock class
 *  3. When EnrolmentService::getStudentDetail() calls getStudentCredentialSummary(),
 *     this mock returns placeholder data
 *  4. When the real credential engine is ready, a new class (e.g.,
 *     RealCredentialDataProvider) will implement the same interface with actual
 *     database queries, and the binding in AppServiceProvider changes to point to it
 *  5. No code in EnrolmentService or EnrolmentController needs to change
 *
 * This demonstrates the Open/Closed Principle: the system is open for extension
 * (new provider implementations) but closed for modification (existing code is
 * untouched when the provider changes).
 *
 * @see \App\Contracts\CredentialDataProviderInterface  The interface this implements
 * @see \App\Providers\AppServiceProvider               Where the binding is configured
 * @see \App\Services\EnrolmentService                  The consumer of this provider
 */

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
    /**
     * Return a zeroed-out credential summary for any student.
     *
     * The return shape matches the expected contract so that the frontend
     * can render the credential section of the student detail view even
     * before real data is available. The 'details' array will eventually
     * contain individual credential records with names, statuses, and dates.
     *
     * @param  int   $studentId The student's user ID (unused in the mock)
     * @return array{total_earned: int, in_progress: int, details: array}
     */
    public function getStudentCredentialSummary(int $studentId): array
    {
        return [
            'total_earned' => 0,
            'in_progress' => 0,
            'details' => [],
        ];
    }
}
