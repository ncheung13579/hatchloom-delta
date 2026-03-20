<?php

/**
 * MockCredentialDataProvider — Static sample data for credential and curriculum APIs.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the mock implementation of CredentialDataProviderInterface.
 *   It returns hardcoded sample data that demonstrates the expected response
 *   structure without requiring Karl's credential engine tables to exist.
 *
 * Important characteristics:
 *   - Returns the SAME data regardless of which $studentId is passed. This is
 *     intentional when using mock providers — the mock just needs to show the correct structure.
 *   - All three credential types are represented: 'credential', 'badge', 'certificate'.
 *   - The curriculum mapping covers all three Alberta PoS areas with realistic
 *     requirement codes and coverage percentages.
 *
 * How to replace with real data (when real services are integrated):
 *   1. Create a new class (e.g., CredentialDataProvider) implementing
 *      CredentialDataProviderInterface that queries the real DB tables
 *   2. Update the binding in AppServiceProvider::register() to point to the
 *      new class instead of this mock
 *   3. No changes needed in DashboardService or any controller
 *
 * @see \App\Contracts\CredentialDataProviderInterface  The interface this implements
 * @see \App\Providers\AppServiceProvider               Where the DI binding is configured
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;

class MockCredentialDataProvider implements CredentialDataProviderInterface
{
    /**
     * Return sample credentials earned by a student.
     *
     * In production, this would query the credentials table filtered by
     * student_id and joined with course data. The three sample entries
     * demonstrate the three credential types Hatchloom supports:
     *   - 'credential' — Formal competency certification
     *   - 'badge'      — Recognition award for participation/achievement
     *   - 'certificate' — Course completion certificate
     */
    public function getStudentCredentials(int $studentId): array
    {
        return [
            [
                'id' => 1,
                'type' => 'credential',
                'name' => 'Entrepreneurial Thinking Foundations',
                'issuing_course' => 'Intro to Entrepreneurship',
                'earned_at' => '2026-02-15T00:00:00Z',
                'status' => 'earned',
            ],
            [
                'id' => 2,
                'type' => 'badge',
                'name' => "Entrepreneur's Choice Award",
                'issuing_course' => 'Marketing Basics',
                'earned_at' => '2026-02-28T00:00:00Z',
                'status' => 'earned',
            ],
            [
                'id' => 3,
                'type' => 'certificate',
                'name' => 'Financial Literacy Completion',
                'issuing_course' => 'Financial Literacy',
                'earned_at' => '2026-03-05T00:00:00Z',
                'status' => 'earned',
            ],
        ];
    }

    /**
     * Return sample Alberta PoS curriculum mapping for a student.
     *
     * The Alberta Program of Studies (PoS) defines competency requirements
     * that Hatchloom courses can satisfy. This mapping shows which requirements
     * a student has met through their course completions. The three areas are:
     *
     *   - Business Studies (8 total requirements) — business planning, marketing, etc.
     *   - CTF Design Studies (7 total requirements) — design thinking, prototyping, etc.
     *   - CALM (5 total requirements) — Career and Life Management: personal finance, goal setting
     *
     * Each 'met_by' field shows which Hatchloom course satisfied that requirement.
     * coverage_percentage = count(requirements_met) / total_requirements.
     */
    public function getStudentCurriculumMapping(int $studentId): array
    {
        return [
            'business_studies' => [
                'area_name' => 'Business Studies',
                'requirements_met' => [
                    ['code' => 'BS-1.1', 'description' => 'Identify business opportunities', 'met_by' => 'Intro to Entrepreneurship'],
                    ['code' => 'BS-2.1', 'description' => 'Develop a business plan', 'met_by' => 'Intro to Entrepreneurship'],
                    ['code' => 'BS-3.1', 'description' => 'Understand marketing principles', 'met_by' => 'Marketing Basics'],
                ],
                'total_requirements' => 8,
                'coverage_percentage' => 0.38, // 3 / 8 = 0.375, rounded to 0.38
            ],
            'ctf_design_studies' => [
                'area_name' => 'CTF Design Studies',
                'requirements_met' => [
                    ['code' => 'CTF-1.1', 'description' => 'Apply design thinking process', 'met_by' => 'Marketing Basics'],
                    ['code' => 'CTF-2.1', 'description' => 'Use digital tools for prototyping', 'met_by' => 'Digital Skills'],
                ],
                'total_requirements' => 7,
                'coverage_percentage' => 0.29, // 2 / 7 = 0.286, rounded to 0.29
            ],
            'calm' => [
                'area_name' => 'Career and Life Management',
                'requirements_met' => [
                    ['code' => 'CALM-1.1', 'description' => 'Set personal and financial goals', 'met_by' => 'Financial Literacy'],
                    ['code' => 'CALM-2.1', 'description' => 'Manage personal finances', 'met_by' => 'Financial Literacy'],
                ],
                'total_requirements' => 5,
                'coverage_percentage' => 0.40, // 2 / 5 = 0.40
            ],
        ];
    }
}
