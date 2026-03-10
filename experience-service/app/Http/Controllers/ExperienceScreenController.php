<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ExperienceScreenService;
use App\Services\ExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for Screen 302 (Experience detail screen) endpoints.
 *
 * Serves three sub-resources of an Experience: enrolled students,
 * contents & delivery, and statistics. Each endpoint validates the
 * Experience exists, then delegates to ExperienceScreenService for
 * data aggregation across local and remote services.
 */
class ExperienceScreenController extends Controller
{
    public function __construct(
        private readonly ExperienceService $experienceService,
        private readonly ExperienceScreenService $screenService
    ) {}

    /** List enrolled students (cohort-level summaries) for an Experience. */
    public function students(Request $request, int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);
        $result = $this->screenService->getEnrolledStudents($experience, $search, $perPage);

        return response()->json($result);
    }

    /**
     * Export enrolled students as a CSV download for an Experience.
     *
     * Provides a downloadable CSV file of student enrolment data scoped
     * to a single experience. School admins use this on Screen 302 to
     * produce attendance or enrolment reports without manual data entry.
     */
    public function exportStudents(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $token = $request->bearerToken() ?? '';
        $rows = $this->screenService->exportStudentList($experience->id, $token);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['student_name', 'student_email', 'cohort_name', 'status', 'enrolled_at']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'experience-students.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Retrieve detail for a specific student within an Experience context.
     *
     * Powers the student drill-down view on Screen 302 — when an admin
     * clicks a student row, this endpoint returns that student's enrolment
     * status and credit progress scoped to this particular experience.
     */
    public function studentDetail(Request $request, int $id, int $studentId): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $token = $request->bearerToken() ?? '';
        $detail = $this->screenService->getStudentDetail($experience->id, $studentId, $token);

        if (!$detail) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found in this experience',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($detail);
    }

    /** Get course contents and block structure for an Experience. */
    public function contents(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($this->screenService->getContentsAndDelivery($experience));
    }

    /** Get aggregated enrolment and completion statistics for an Experience. */
    public function statistics(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($this->screenService->getExperienceStatistics($experience));
    }
}
