<?php

/**
 * CredentialDataProviderInterface — Strategy pattern contract for credential data.
 *
 * Design pattern: Strategy
 *   This interface defines the Strategy contract for accessing student
 *   credentials and curriculum mappings. The DashboardService depends on
 *   this interface (not a concrete class), allowing the implementation to
 *   be swapped without changing any service or controller code.
 *
 * Current binding (mock):
 *   AppServiceProvider binds this to MockCredentialDataProvider, which
 *   returns hardcoded sample credentials and curriculum mappings.
 *
 * Future binding (when real services are integrated):
 *   When Karl (Role B / Riipen lead) delivers the credential engine, a
 *   real implementation will query the `credentials` and `curriculum_mappings`
 *   tables. The only change needed is updating the binding in AppServiceProvider.
 *
 * Where it's used:
 *   - DashboardService::getStudentDrillDown() — fetches credentials and
 *     curriculum mapping for the student detail view
 *   - Widget context — passed to widgets that need credential data
 *
 * @see \App\Services\MockCredentialDataProvider  Current mock implementation
 * @see \App\Providers\AppServiceProvider          Where the binding is configured
 */

declare(strict_types=1);

namespace App\Contracts;

interface CredentialDataProviderInterface
{
    /**
     * Return credentials earned by a specific student.
     *
     * Each credential is an associative array with: id, type (credential/badge/certificate),
     * name, issuing_course, earned_at (ISO 8601), and status.
     *
     * @param  int   $studentId  The user ID of the student
     * @return array<int, array<string, mixed>>  List of credential records
     */
    public function getStudentCredentials(int $studentId): array;

    /**
     * Return Alberta PoS curriculum mapping for a specific student.
     *
     * Returns a nested structure keyed by PoS area (business_studies,
     * ctf_design_studies, calm), each containing: area_name, requirements_met
     * (array of met requirements with code, description, met_by), total_requirements,
     * and coverage_percentage (0.0 to 1.0).
     *
     * @param  int   $studentId  The user ID of the student
     * @return array<string, array<string, mixed>>  Curriculum mapping keyed by PoS area
     */
    public function getStudentCurriculumMapping(int $studentId): array;
}
