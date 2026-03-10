<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for accessing student credential data in the Enrolment Service.
 *
 * In D1, fulfilled by MockCredentialDataProvider with empty/zero data.
 * When Karl's credential engine is available, a real implementation will
 * query the credentials tables and return actual earned/in-progress counts.
 */
interface CredentialDataProviderInterface
{
    /** Return a credential summary for a specific student. */
    public function getStudentCredentialSummary(int $studentId): array;
}
