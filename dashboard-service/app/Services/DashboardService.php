<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class DashboardService
{
    public function getDashboardOverview(): array
    {
        $user = Auth::user();
        $school = $user->school;
        $token = request()->bearerToken();

        $warnings = [];
        $experienceData = null;
        $enrolmentStats = null;

        // Call Experience Service
        try {
            $experienceResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.experience.url') . '/api/school/experiences');

            if ($experienceResponse->successful()) {
                $experienceData = $experienceResponse->json();
            } else {
                $warnings[] = [
                    'type' => 'service_degraded',
                    'message' => 'Experience service is unavailable',
                    'severity' => 'warning',
                ];
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'type' => 'service_degraded',
                'message' => 'Experience service is unavailable',
                'severity' => 'warning',
            ];
        }

        // Call Enrolment Service
        try {
            $enrolmentResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments/statistics');

            if ($enrolmentResponse->successful()) {
                $enrolmentStats = $enrolmentResponse->json();
            } else {
                $warnings[] = [
                    'type' => 'service_degraded',
                    'message' => 'Enrolment service is unavailable',
                    'severity' => 'warning',
                ];
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'type' => 'service_degraded',
                'message' => 'Enrolment service is unavailable',
                'severity' => 'warning',
            ];
        }

        // Merge warnings from enrolment stats
        if ($enrolmentStats && isset($enrolmentStats['warnings'])) {
            $warnings = array_merge($warnings, $enrolmentStats['warnings']);
        }

        $totalEnrolled = $enrolmentStats['enrolled'] ?? 0;
        $assigned = $enrolmentStats['assigned'] ?? 0;
        $notAssigned = $enrolmentStats['not_assigned'] ?? 0;
        $totalStudents = $enrolmentStats['total_students'] ?? 0;

        return [
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
            ],
            'cohorts' => [
                'active' => 0,
                'completed' => 0,
                'upcoming' => 0,
                'total' => 0,
            ],
            'students' => [
                'total_enrolled' => $totalEnrolled,
                'active_in_cohorts' => $assigned,
                'not_assigned' => $notAssigned,
            ],
            'statistics' => [
                'enrolment_rate' => $totalStudents > 0 ? round($totalEnrolled / $totalStudents, 2) : 0,
                'average_completion' => 0.0,
                'average_credit_progress' => 0.0,
            ],
            'warnings' => $warnings,
        ];
    }

    public function getStudentDrillDown(int $studentId): ?array
    {
        $user = Auth::user();
        $token = request()->bearerToken();

        $student = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return null;
        }

        $enrolments = [];

        // Try to get enrolment data from Enrolment Service
        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments', [
                    'search' => $student->name,
                ]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                foreach ($data as $item) {
                    if (($item['student_id'] ?? null) == $studentId) {
                        $enrolments = $item['cohort_assignments'] ?? [];
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Degraded response — no enrolment data
        }

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'enrolments' => $enrolments,
            'progress' => [
                'courses_completed' => 0,
                'courses_in_progress' => 0,
                'overall_completion' => 0.0,
            ],
            'credentials' => [],
        ];
    }
}
