<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;
use App\Models\Experience;
use App\Models\ExperienceCourse;
use Illuminate\Support\Facades\Http;

/**
 * Service layer for Screen 302 (Experience Screen) data aggregation.
 *
 * This service assembles the three data panels shown on the Experience
 * detail screen: enrolled students, contents & delivery, and statistics.
 * It combines local Experience data with remote data fetched from the
 * Enrolment Service (cohort/student counts) and course data from the
 * CourseDataProviderInterface (course blocks). All remote calls degrade
 * gracefully, returning empty/zero data on failure.
 */
class ExperienceScreenService
{
    public function __construct(
        private readonly CourseDataProviderInterface $courseDataProvider
    ) {}

    /**
     * Fetch individual enrolled students by querying the Enrolment Service.
     *
     * Makes an inter-service HTTP call to the Enrolment Service's enrolments
     * endpoint, filtered by experience_id. Returns per-student records with
     * name, email, cohort name, status, and enrolment date. Degrades to
     * empty data on network failure.
     *
     * When a search term is provided, students are filtered by name using a
     * case-insensitive partial match.
     */
    public function getEnrolledStudents(Experience $experience, ?string $search = null, int $perPage = 15): array
    {
        $token = request()->bearerToken();
        $data = [];
        $total = 0;

        try {
            $enrolmentResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments', [
                    'experience_id' => $experience->id,
                ]);

            if ($enrolmentResponse->successful()) {
                $students = collect($enrolmentResponse->json('data', []));

                // Filter by student name/email when a search term is provided.
                if ($search) {
                    $searchLower = mb_strtolower($search);
                    $students = $students->filter(function (array $student) use ($searchLower): bool {
                        $name = mb_strtolower($student['name'] ?? '');
                        $email = mb_strtolower($student['email'] ?? '');
                        return str_contains($name, $searchLower)
                            || str_contains($email, $searchLower);
                    });
                }

                // Flatten: one record per student-cohort assignment.
                foreach ($students as $student) {
                    foreach ($student['cohort_assignments'] ?? [] as $assignment) {
                        $data[] = [
                            'student_id' => $student['student_id'],
                            'student_name' => $student['name'] ?? 'Unknown',
                            'student_email' => $student['email'] ?? '',
                            'cohort_id' => $assignment['cohort_id'],
                            'cohort_name' => $assignment['cohort_name'] ?? '',
                            'status' => $assignment['status'] ?? 'enrolled',
                            'enrolled_at' => $assignment['enrolled_at'] ?? '',
                        ];
                    }
                }

                $total = count($data);
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

    /**
     * Build a flat CSV-ready list of students enrolled in an Experience.
     *
     * Calls the Enrolment Service's enrolments endpoint filtered by
     * experience_id to retrieve individual student records. Returns one
     * row per student with their name, email, cohort name, status, and
     * enrolment date. Degrades to an empty array on network failure.
     */
    public function exportStudentList(int $experienceId, string $token): array
    {
        $rows = [];

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments', [
                    'experience_id' => $experienceId,
                ]);

            if ($response->successful()) {
                $students = collect($response->json('data', []));

                // Build one CSV row per student-cohort assignment.
                foreach ($students as $student) {
                    foreach ($student['cohort_assignments'] ?? [] as $assignment) {
                        $rows[] = [
                            'student_name' => $student['name'] ?? 'Unknown',
                            'student_email' => $student['email'] ?? '',
                            'cohort_name' => $assignment['cohort_name'] ?? '',
                            'status' => $assignment['status'] ?? 'enrolled',
                            'enrolled_at' => $assignment['enrolled_at'] ?? '',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Degraded — return empty export on failure
        }

        return $rows;
    }

    /**
     * Retrieve detail for a specific student within an Experience context.
     *
     * Calls the Enrolment Service's enrolments endpoint filtered by both
     * student_id and experience_id to perform a real lookup of the student's
     * cohort assignment. Returns the student's enrolment status within this
     * experience plus mock credit progress data. This powers the student
     * drill-down view on Screen 302.
     *
     * Returns null when the student is not found in any cohort for this
     * experience, allowing the controller to return a 404 response.
     */
    public function getStudentDetail(int $experienceId, int $studentId, string $token): ?array
    {
        try {
            $enrolmentResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments', [
                    'student_id' => $studentId,
                    'experience_id' => $experienceId,
                ]);

            if ($enrolmentResponse->successful()) {
                $students = collect($enrolmentResponse->json('data', []));

                // Find the student and their first cohort assignment.
                $student = $students->first();
                if ($student) {
                    $assignment = collect($student['cohort_assignments'] ?? [])->first();
                    if ($assignment) {
                        return [
                            'student_id' => $studentId,
                            'student_name' => $student['name'] ?? 'Unknown',
                            'student_email' => $student['email'] ?? '',
                            'experience_id' => $experienceId,
                            'cohort_id' => $assignment['cohort_id'],
                            'cohort_name' => $assignment['cohort_name'] ?? '',
                            'status' => $assignment['status'] ?? 'enrolled',
                            'enrolled_at' => $assignment['enrolled_at'] ?? '',
                            'credits' => [
                                'earned' => 0,
                                'total' => 0,
                                'progress' => 0.0,
                            ],
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Degraded — return null on failure (treated as not found)
        }

        return null;
    }

    /**
     * Build the contents & delivery panel by enriching local course
     * associations with full course data (name, blocks) from the
     * MockCourseDataProvider.
     */
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

    /**
     * Aggregate enrolment and completion statistics for an Experience.
     *
     * Fetches cohort data from the Enrolment Service and computes totals.
     * Completion and credit progress are stubbed with zeros for D1 since
     * real progress tracking depends on Team Papa's Course Service.
     */
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
                // Sum removed_count across all cohorts (field provided by Enrolment Service)
                $removedStudents = (int) $cohorts->sum('removed_count');
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
