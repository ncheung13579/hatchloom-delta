<?php

declare(strict_types=1);

/**
 * CredentialDataProviderInterface — Strategy pattern contract for credential data.
 *
 * Part of the Strategy pattern (SDD Section 6.4) that allows the Enrolment Service
 * to access student credential data without being coupled to a specific data source.
 *
 * WHY THIS EXISTS (swappable provider concept):
 * Student credentials (badges, certificates, skill validations) are owned by Karl's
 * credential engine (Role B), which is not yet built. Rather than hardcoding mock
 * data throughout the service, we define this interface and bind it to a mock
 * implementation (MockCredentialDataProvider) in AppServiceProvider. When Karl's
 * engine is ready, the swap is a one-line change in AppServiceProvider — no
 * controller or service code needs to change.
 *
 * HOW IT FITS IN:
 *  - EnrolmentService depends on this interface (constructor injection)
 *  - AppServiceProvider binds the interface to MockCredentialDataProvider currently
 *  - The studentDetail() endpoint in EnrolmentController surfaces credential data
 *    to the admin's drill-down view on Screen 303
 *
 * @see \App\Services\MockCredentialDataProvider  Mock implementation (current)
 * @see \App\Services\EnrolmentService            Consumer of this interface
 * @see \App\Providers\AppServiceProvider          Where the binding is configured
 */

namespace App\Contracts;

/**
 * Contract for accessing student credential data in the Enrolment Service.
 *
 * Currently fulfilled by MockCredentialDataProvider with placeholder data.
 * When Karl's credential engine is available, a real implementation will
 * query the credentials tables and return actual earned/in-progress counts.
 */
interface CredentialDataProviderInterface
{
    /**
     * Return a credential summary for a specific student.
     *
     * Expected return shape:
     *   [
     *       'total_earned' => int,    // Number of credentials the student has earned
     *       'in_progress'  => int,    // Number of credentials in progress
     *       'details'      => array,  // Detailed credential records (placeholder data with mock provider)
     *   ]
     *
     * @param  int   $studentId The student's user ID
     * @return array<string, mixed>  Credential summary data
     */
    public function getStudentCredentialSummary(int $studentId): array;
}
