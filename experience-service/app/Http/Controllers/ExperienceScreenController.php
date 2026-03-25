<?php

/**
 * ExperienceScreenController — REST controller for Screen 302 (Experience detail screen).
 *
 * Architecture role:
 *   This controller serves the sub-resource endpoints nested under a single Experience:
 *   students, student detail, student export (CSV), contents & delivery, and statistics.
 *   These correspond to the tabs/panels on the Experience detail screen in the frontend.
 *
 *   Like ExperienceController, this follows the thin-controller pattern: each method
 *   validates the parent Experience exists, then delegates to ExperienceScreenService
 *   for the heavy lifting (cross-service HTTP calls, data aggregation).
 *
 * URL structure:
 *   All routes are nested under /api/school/experiences/{id}/:
 *     GET  .../students          — paginated student list (from Enrolment Service)
 *     GET  .../students/export   — CSV download of students
 *     GET  .../students/{sid}    — single student detail drill-down
 *     GET  .../contents          — course blocks and structure (from CourseDataProvider)
 *     GET  .../statistics        — aggregated enrolment/completion stats
 *
 * Cross-service dependency:
 *   Students and statistics data come from the Enrolment Service (port 8003).
 *   Contents data comes from the CourseDataProviderInterface (currently mocked).
 *   All remote calls degrade gracefully on failure.
 *
 * @see \App\Services\ExperienceScreenService  Data aggregation layer for Screen 302
 * @see \App\Services\ExperienceService        Used here only for Experience lookup/validation
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ExperienceScreenService;
use App\Services\ExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExperienceScreenController extends Controller
{
    private static function sanitizeCsvValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return $value;
    }

    /**
     * @param ExperienceService       $experienceService  Used solely to look up and validate the parent Experience.
     * @param ExperienceScreenService $screenService      Handles all data aggregation for Screen 302 sub-resources.
     */
    public function __construct(
        private readonly ExperienceService $experienceService,
        private readonly ExperienceScreenService $screenService
    ) {}

    /**
     * GET /api/school/experiences/{id}/students — List enrolled students for an Experience.
     *
     * Fetches student enrolment data from the Enrolment Service, optionally filtered
     * by a search term (name or email). Returns one record per student-cohort assignment,
     * meaning a student enrolled in multiple cohorts of the same experience appears
     * multiple times.
     */
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
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
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
                fputcsv($handle, array_map(self::sanitizeCsvValue(...), $row));
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
        // Students can only view their own detail; parents can view their linked children's.
        $user = Auth::user();
        if ($user->role === 'student' && $user->id !== $studentId) {
            return response()->json(['error' => true, 'message' => 'Forbidden', 'code' => 'FORBIDDEN'], 403);
        }
        if ($user->role === 'parent') {
            $isLinked = DB::table('parent_student_links')
                ->where('parent_id', $user->id)
                ->where('student_id', $studentId)
                ->exists();
            if (!$isLinked) {
                return response()->json(['error' => true, 'message' => 'Forbidden', 'code' => 'FORBIDDEN'], 403);
            }
        }

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

    /**
     * GET /api/school/experiences/{id}/contents — Get course contents and block structure.
     *
     * Returns each course in the Experience with its internal block structure.
     * Block data comes from the CourseDataProviderInterface — currently
     * mock data, but will be real upstream data from Team Papa's Course Service when real services are integrated.
     */
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

    /**
     * GET /api/school/experiences/{id}/statistics — Aggregated enrolment/completion stats.
     *
     * Returns total/active/removed student counts, completion rates, and credit progress.
     * Student counts come from the Enrolment Service; completion and credit data are
     * stubbed with zeros because real progress tracking depends on Team Papa's
     * Course Service integration (will be available when real services are integrated).
     */
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
