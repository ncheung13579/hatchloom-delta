<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for accessing student credential and curriculum mapping data.
 *
 * In D1, fulfilled by MockCredentialDataProvider with static sample data.
 * When Karl's credential engine is available, a real implementation will
 * query the credentials and curriculum_mappings tables directly.
 */
interface CredentialDataProviderInterface
{
    /** Return credentials earned by a specific student. */
    public function getStudentCredentials(int $studentId): array;

    /** Return Alberta PoS curriculum mapping for a specific student. */
    public function getStudentCurriculumMapping(int $studentId): array;
}
