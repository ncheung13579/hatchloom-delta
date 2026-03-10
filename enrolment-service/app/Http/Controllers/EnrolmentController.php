<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\User;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles student enrolment into cohorts and enrolment data retrieval (Screen 303).
 *
 * Provides endpoints for the enrolment overview, enrol/remove operations,
 * aggregate statistics, and CSV export. Input validation and guard checks
 * live here; business logic is delegated to EnrolmentService.
 */
class EnrolmentController extends Controller
{
    public function __construct(
        private readonly EnrolmentService $enrolmentService
    ) {}

    /**
     * List students with their cohort assignments, supporting optional filters.
     *
     * Accepts query parameters to narrow results by experience, cohort, or grade
     * so that school admins can drill into specific slices of the enrolment data
     * without loading the entire student roster.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);

        $filters = array_filter([
            'grade' => $request->query('grade'),
            'experience_id' => $request->query('experience_id') !== null
                ? (int) $request->query('experience_id')
                : null,
            'cohort_id' => $request->query('cohort_id') !== null
                ? (int) $request->query('cohort_id')
                : null,
        ], fn($value) => $value !== null);

        $overview = $this->enrolmentService->getEnrolmentOverview($search, $perPage, $filters);

        return response()->json([
            'data' => $overview->items(),
            'meta' => [
                'current_page' => $overview->currentPage(),
                'last_page' => $overview->lastPage(),
                'per_page' => $overview->perPage(),
                'total' => $overview->total(),
            ],
        ]);
    }

    /**
     * Enrol a student into a cohort.
     *
     * Runs a 5-step validation chain before creating the enrolment:
     *  1. Find the cohort (404 if missing)
     *  2. Verify the student exists and belongs to the same school as the admin
     *  3. Check the cohort is in active status (only active cohorts accept enrolments)
     *  4. Check the cohort has not reached its capacity limit
     *  5. Check for duplicate enrolment (including removed ones — re-enrolment is not allowed)
     */
    public function enrol(Request $request, int $cohortId): JsonResponse
    {
        // Step 1: Find the cohort
        $cohort = Cohort::find($cohortId);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'student_id' => 'required|integer',
        ]);

        $studentId = $validated['student_id'];

        // Step 2: Verify student belongs to same school
        $student = User::where('id', $studentId)
            ->where('school_id', Auth::user()->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found or not in your school',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        // Step 3: Check cohort is active
        if ($cohort->status !== 'active') {
            return response()->json([
                'error' => true,
                'message' => 'Cohort is not active',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        // Step 4: Check capacity
        if ($cohort->capacity) {
            $currentCount = $cohort->activeEnrolments()->count();
            if ($currentCount >= $cohort->capacity) {
                return response()->json([
                    'error' => true,
                    'message' => 'Cohort is at full capacity',
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        // Step 5: Check for duplicate (includes removed enrolments — no re-enrolment)
        $existing = CohortEnrolment::where('cohort_id', $cohort->id)
            ->where('student_id', $studentId)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => true,
                'message' => 'Student is already enrolled in this cohort',
                'code' => 'DUPLICATE_ENROLMENT',
            ], 422);
        }

        $enrolment = $this->enrolmentService->enrolStudent($cohort, $studentId);

        return response()->json([
            'id' => $enrolment->id,
            'cohort_id' => $enrolment->cohort_id,
            'student_id' => $enrolment->student_id,
            'status' => $enrolment->status,
            'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
        ], 201);
    }

    public function remove(int $cohortId, int $studentId): JsonResponse
    {
        $cohort = Cohort::find($cohortId);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $enrolment = $this->enrolmentService->removeStudent($cohort, $studentId);

        if (!$enrolment) {
            return response()->json([
                'error' => true,
                'message' => 'Enrolment not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json(['message' => 'Student removed from cohort']);
    }

    public function statistics(): JsonResponse
    {
        return response()->json($this->enrolmentService->calculateStatistics());
    }

    /**
     * Return detailed enrolment information for a single student.
     *
     * Provides all cohort assignments, experience names, and a mock credential
     * summary so the admin can inspect one student's full enrolment picture
     * without navigating away from the Enrolment screen (303).
     */
    public function studentDetail(int $studentId): JsonResponse
    {
        $detail = $this->enrolmentService->getStudentDetail($studentId);

        if ($detail === null) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($detail);
    }

    public function export(): StreamedResponse
    {
        $rows = $this->enrolmentService->exportEnrolmentList();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['student_name', 'student_email', 'cohort_name', 'experience_name', 'status', 'enrolled_at', 'removed_at']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'enrolments.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
