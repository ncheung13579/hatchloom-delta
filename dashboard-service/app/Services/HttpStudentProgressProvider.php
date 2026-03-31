<?php

/**
 * HttpStudentProgressProvider — Real implementation calling Team Papa's Course Service.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the production implementation of StudentProgressProviderInterface.
 *   It fetches student progress and engagement metrics from Papa's Course Service.
 *
 * Ideal endpoints (Team Papa's Course Service):
 *   GET  /api/progress/problems-tackled?experience_ids[]=1&experience_ids[]=2
 *     Response: {"count": 12}
 *
 *   GET  /api/progress/credit-progress?experience_ids[]=1&experience_ids[]=2
 *     Response: {"progress": 0.45}
 *
 *   GET  /api/progress/timely-completion?total_enrolled=10&assigned=8
 *     Response: {"rate": 0.72}
 *
 *   POST /api/progress/pos-coverage
 *     Body: {"students": [{"id": 4, "name": "Student 1"}, ...]}
 *     Response: {
 *       "student_coverage": [
 *         {
 *           "student_id": 4,
 *           "student_name": "Student 1",
 *           "coverage": {
 *             "business_studies": {"completed": 3, "total": 8, "percentage": 0.38},
 *             "ctf_design_studies": {"completed": 2, "total": 7, "percentage": 0.29},
 *             "calm": {"completed": 2, "total": 5, "percentage": 0.40}
 *           },
 *           "overall_coverage": 0.35
 *         }
 *       ],
 *       "school_averages": {
 *         "business_studies": 0.38,
 *         "ctf_design_studies": 0.29,
 *         "calm": 0.40
 *       }
 *     }
 *
 *   POST /api/progress/engagement
 *     Body: {"students": [{"id": 4, "name": "Student 1"}, ...]}
 *     Response: {
 *       "student_engagement": [
 *         {
 *           "student_id": 4,
 *           "student_name": "Student 1",
 *           "login_days_last_30": 12,
 *           "activities_completed": 15,
 *           "total_activities": 20,
 *           "completion_rate": 0.75,
 *           "last_active_at": "2026-03-28T14:30:00+00:00"
 *         }
 *       ],
 *       "school_averages": {
 *         "avg_login_days": 12.0,
 *         "avg_completion_rate": 0.75,
 *         "active_student_count": 1
 *       }
 *     }
 *
 * Error handling:
 *   All methods degrade gracefully — returning 0, 0.0, or empty structures on
 *   failure so the Dashboard Service can still render partial data.
 *
 * @see \App\Contracts\StudentProgressProviderInterface  The interface this implements
 * @see \App\Services\MockStudentProgressProvider        The mock fallback
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StudentProgressProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpStudentProgressProvider implements StudentProgressProviderInterface
{
    public function countProblemsTackled(array $experiences): int
    {
        if (empty($experiences)) {
            return 0;
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return 0;
        }

        try {
            $experienceIds = array_column($experiences, 'id');

            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/progress/problems-tackled", [
                    'experience_ids' => $experienceIds,
                ]);

            if (!$response->successful()) {
                Log::warning('HttpStudentProgressProvider: Failed to fetch problems tackled', [
                    'status' => $response->status(),
                ]);
                return 0;
            }

            return (int) ($response->json('count', 0));
        } catch (\Exception $e) {
            Log::error('HttpStudentProgressProvider: Error fetching problems tackled', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function calculateCreditProgress(array $experiences): float
    {
        if (empty($experiences)) {
            return 0.0;
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return 0.0;
        }

        try {
            $experienceIds = array_column($experiences, 'id');

            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/progress/credit-progress", [
                    'experience_ids' => $experienceIds,
                ]);

            if (!$response->successful()) {
                Log::warning('HttpStudentProgressProvider: Failed to fetch credit progress', [
                    'status' => $response->status(),
                ]);
                return 0.0;
            }

            return (float) ($response->json('progress', 0.0));
        } catch (\Exception $e) {
            Log::error('HttpStudentProgressProvider: Error fetching credit progress', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function calculateTimelyCompletion(int $totalEnrolled, int $assigned): float
    {
        if ($totalEnrolled === 0) {
            return 0.0;
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return 0.0;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/progress/timely-completion", [
                    'total_enrolled' => $totalEnrolled,
                    'assigned' => $assigned,
                ]);

            if (!$response->successful()) {
                Log::warning('HttpStudentProgressProvider: Failed to fetch timely completion', [
                    'status' => $response->status(),
                ]);
                return 0.0;
            }

            return (float) ($response->json('rate', 0.0));
        } catch (\Exception $e) {
            Log::error('HttpStudentProgressProvider: Error fetching timely completion', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getPosCoverage(array $students): array
    {
        $default = [
            'student_coverage' => [],
            'school_averages' => [
                'business_studies' => 0.0,
                'ctf_design_studies' => 0.0,
                'calm' => 0.0,
            ],
        ];

        if (empty($students)) {
            return $default;
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return $default;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->post("{$url}/api/progress/pos-coverage", [
                    'students' => $students,
                ]);

            if (!$response->successful()) {
                Log::warning('HttpStudentProgressProvider: Failed to fetch PoS coverage', [
                    'status' => $response->status(),
                ]);
                return $default;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('HttpStudentProgressProvider: Error fetching PoS coverage', [
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    public function getEngagementRates(array $students): array
    {
        $default = [
            'student_engagement' => [],
            'school_averages' => [
                'avg_login_days' => 0,
                'avg_completion_rate' => 0.0,
                'active_student_count' => 0,
            ],
        ];

        if (empty($students)) {
            return $default;
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return $default;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->post("{$url}/api/progress/engagement", [
                    'students' => $students,
                ]);

            if (!$response->successful()) {
                Log::warning('HttpStudentProgressProvider: Failed to fetch engagement rates', [
                    'status' => $response->status(),
                ]);
                return $default;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('HttpStudentProgressProvider: Error fetching engagement rates', [
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }
}
