<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Experience;
use App\Models\ExperienceCourse;
use Illuminate\Support\Facades\Http;

class ExperienceScreenService
{
    public function __construct(
        private readonly MockCourseDataProvider $courseDataProvider
    ) {}

    public function getEnrolledStudents(Experience $experience, ?string $search = null, int $perPage = 15): array
    {
        $token = request()->bearerToken();
        $data = [];
        $total = 0;

        try {
            $cohortsResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts', [
                    'experience_id' => $experience->id,
                ]);

            if ($cohortsResponse->successful()) {
                $cohorts = collect($cohortsResponse->json('data', []));
                $total = $cohorts->sum('student_count');

                // Return cohort-level summary as individual student data isn't
                // directly available per-experience without a dedicated endpoint.
                // In D2, a dedicated endpoint will provide per-student records.
                foreach ($cohorts as $cohort) {
                    $data[] = [
                        'cohort_id' => $cohort['id'],
                        'cohort_name' => $cohort['name'],
                        'status' => $cohort['status'],
                        'student_count' => $cohort['student_count'],
                        'capacity' => $cohort['capacity'],
                    ];
                }
            }
        } catch (\Exception $e) {
            // Degraded response — return empty data on failure
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function getContentsAndDelivery(Experience $experience): array
    {
        $courses = [];
        foreach ($experience->courses as $expCourse) {
            $courseData = $this->courseDataProvider->getCourse($expCourse->course_id);
            if ($courseData) {
                $courses[] = [
                    'id' => $courseData['id'],
                    'name' => $courseData['name'],
                    'sequence' => $expCourse->sequence,
                    'blocks' => $courseData['blocks'],
                ];
            }
        }

        return [
            'experience_id' => $experience->id,
            'courses' => $courses,
        ];
    }

    public function getExperienceStatistics(Experience $experience): array
    {
        $token = request()->bearerToken();
        $totalStudents = 0;
        $activeStudents = 0;
        $removedStudents = 0;

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts', [
                    'experience_id' => $experience->id,
                ]);

            if ($response->successful()) {
                $cohorts = collect($response->json('data', []));
                $totalStudents = $cohorts->sum('student_count');
                $activeStudents = $cohorts->where('status', 'active')->sum('student_count');
            }
        } catch (\Exception $e) {
            // Degraded — use zeros on failure
        }

        $completionRate = $totalStudents > 0 ? round($activeStudents / $totalStudents, 2) : 0.0;

        return [
            'experience_id' => $experience->id,
            'enrolment' => [
                'total_students' => $totalStudents,
                'active' => $activeStudents,
                'removed' => $removedStudents,
            ],
            'completion' => [
                'completed' => 0,
                'in_progress' => $activeStudents,
                'not_started' => 0,
                'completion_rate' => $completionRate,
            ],
            'credit_progress' => [
                'average' => 0.0,
                'students_with_credits' => 0,
            ],
        ];
    }
}
