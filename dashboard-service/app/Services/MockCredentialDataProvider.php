<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;

/**
 * Mock credential data for D1 testing.
 *
 * Returns static sample credentials and curriculum mappings. When Karl's
 * credential engine tables are available, replace this binding in
 * AppServiceProvider with the real implementation.
 */
class MockCredentialDataProvider implements CredentialDataProviderInterface
{
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
                'coverage_percentage' => 0.38,
            ],
            'ctf_design_studies' => [
                'area_name' => 'CTF Design Studies',
                'requirements_met' => [
                    ['code' => 'CTF-1.1', 'description' => 'Apply design thinking process', 'met_by' => 'Marketing Basics'],
                    ['code' => 'CTF-2.1', 'description' => 'Use digital tools for prototyping', 'met_by' => 'Digital Skills'],
                ],
                'total_requirements' => 7,
                'coverage_percentage' => 0.29,
            ],
            'calm' => [
                'area_name' => 'Career and Life Management',
                'requirements_met' => [
                    ['code' => 'CALM-1.1', 'description' => 'Set personal and financial goals', 'met_by' => 'Financial Literacy'],
                    ['code' => 'CALM-2.1', 'description' => 'Manage personal finances', 'met_by' => 'Financial Literacy'],
                ],
                'total_requirements' => 5,
                'coverage_percentage' => 0.40,
            ],
        ];
    }
}
