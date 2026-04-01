<?php

declare(strict_types=1);

/**
 * EnrolmentController — Manages student enrolment into cohorts (Screen 303).
 *
 * Part of the Enrolment Service (port 8003), the leaf service in Team Delta's
 * microservice architecture. This controller owns the HTTP interface for:
 *  - Enrolment overview (paginated student list with cohort assignments)
 *  - Enrol/remove operations (adding and soft-removing students from cohorts)
 *  - Aggregate statistics with automated warning generation
 *  - Student drill-down detail view
 *  - CSV export of all enrolment records
 *
 * Design patterns present:
 *  - Controller -> Service -> Model (Repository pattern): Input validation and
 *    HTTP response formatting live here; business logic is in EnrolmentService.
 *  - Observer pattern (indirect): The enrol() and remove() actions trigger domain
 *    events (StudentEnrolled, StudentRemoved) via EnrolmentService, which decouple
 *    the core enrolment action from its side effects (dashboard updates, teacher
 *    notifications, credential checks).
 *
 * @see \App\Services\EnrolmentService  Business logic layer
 * @see \App\Events\StudentEnrolled     Event dispatched on enrolment
 * @see \App\Events\StudentRemoved      Event dispatched on removal
 */

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\RequiresTeacherAdmin;
use App\Http\Controllers\Traits\SanitizesCsvOutput;
use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\User;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    use RequiresTeacherAdmin;
    use SanitizesCsvOutput;

    /**
     * Constructor injection of EnrolmentService.
     *
     * EnrolmentService itself depends on CredentialDataProviderInterface, which
     * is resolved by Laravel's container via the binding in AppServiceProvider.
     * This means swapping the credential provider (mock vs. real) does not
     * require any changes to this controller.
     */
    public function __construct(
        private readonly EnrolmentService $enrolmentService
    ) {}

    /**
     * List students with their cohort assignments, supporting optional filters.
     *
     * Accepts query parameters to narrow results by experience, cohort, or grade
     * so that school admins can drill into specific slices of the enrolment data
     * without loading the entire student roster.
     *
     * The response includes pagination metadata (current_page, last_page, per_page,
     * total) so the frontend can render page controls. Each student record includes
     * an assignment_status field derived from their enrolment state:
     *  - "assigned": has at least one active enrolment in an active cohort
     *  - "removed": all enrolments have status=removed
     *  - "not_assigned": no enrolments at all
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        // Build a filters array, removing any null values so downstream code
        // only processes filters that were actually provided by the client.
        $filters = array_filter([
            'grade' => $request->query('grade'),
            'experience_id' => $request->query('experience_id') !== null
                ? (int) $request->query('experience_id')
                : null,
            'cohort_id' => $request->query('cohort_id') !== null
                ? (int) $request->query('cohort_id')
                : null,
            'student_id' => $request->query('student_id') !== null
                ? (int) $request->query('student_id')
                : null,
        ], fn($value) => $value !== null);

        // Role-based data scoping: students and parents are restricted to viewing
        // only their own data. This overrides any client-supplied student_id filter
        // to prevent a student from browsing another student's enrolment records.
        $user = Auth::user();
        if ($user->role === 'student') {
            $filters['student_id'] = $user->id;
        } elseif ($user->role === 'parent') {
            // Query parent_student_links to find all children this parent can view
            $childIds = DB::table('parent_student_links')
                ->where('parent_id', $user->id)
                ->pluck('student_id')
                ->toArray();
            $filters['student_ids'] = $childIds;
        }

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
     *
     * This multi-step guard approach is intentional: each check has a distinct error
     * message and code so the frontend can display context-specific feedback. The
     * checks are ordered from cheapest (primary key lookup) to most expensive
     * (duplicate scan) for efficiency.
     *
     * On success, dispatches a StudentEnrolled event that triggers the Observer
     * pattern listeners (dashboard counts, teacher notification, credential check).
     */
    public function enrol(Request $request, int $cohortId): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('enrol students')) {
            return $denied;
        }

        $validated = $request->validate(['student_id' => 'required|integer']);
        $studentId = $validated['student_id'];

        // Wrap validation + creation in a transaction with a pessimistic lock on the
        // cohort row. This prevents the TOCTOU race condition where two concurrent
        // requests both pass the capacity check and exceed the cohort's limit.
        return DB::transaction(function () use ($cohortId, $studentId) {
            $cohort = Cohort::lockForUpdate()->find($cohortId);
            if (!$cohort) {
                return $this->errorResponse('Cohort not found', 'NOT_FOUND', 404);
            }

            $validationError = $this->validateEnrolment($cohort, $studentId);
            if ($validationError) {
                return $validationError;
            }

            $enrolment = $this->enrolmentService->enrolStudent($cohort, $studentId);

            return response()->json([
                'id' => $enrolment->id,
                'cohort_id' => $enrolment->cohort_id,
                'student_id' => $enrolment->student_id,
                'status' => $enrolment->status,
                'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
            ], 201);
        });
    }

    /**
     * Validate all preconditions for enrolling a student into a cohort.
     *
     * Returns a JsonResponse describing the validation failure, or null if all checks pass.
     * Extracted from enrol() to reduce method length and isolate validation logic.
     */
    private function validateEnrolment(Cohort $cohort, int $studentId): ?JsonResponse
    {
        // Verify student belongs to the same school and is a student
        $student = User::where('id', $studentId)
            ->where('school_id', Auth::user()->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return $this->errorResponse('Student not found or not in your school', 'VALIDATION_ERROR', 422);
        }

        if ($cohort->status !== \App\Enums\CohortStatus::ACTIVE->value) {
            return $this->errorResponse('Cohort is not active', 'VALIDATION_ERROR', 422);
        }

        if ($cohort->isFull()) {
            return $this->errorResponse('Cohort is at full capacity', 'VALIDATION_ERROR', 422);
        }

        // Check for an active enrolment — removed students can be re-enrolled.
        $existing = CohortEnrolment::where('cohort_id', $cohort->id)
            ->where('student_id', $studentId)
            ->where('status', 'enrolled')
            ->first();

        if ($existing) {
            return $this->errorResponse('Student is already enrolled in this cohort', 'DUPLICATE_ENROLMENT', 422);
        }

        return null;
    }

    /**
     * Soft-remove a student from a cohort.
     *
     * Does NOT hard-delete the enrolment record. Instead, sets status='removed' and
     * records a removed_at timestamp. This preserves the audit trail for reporting
     * and CSV export. Dispatches a StudentRemoved event on success.
     *
     * @param int $cohortId  The cohort to remove the student from
     * @param int $studentId The student to remove
     */
    public function remove(int $cohortId, int $studentId): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('remove students')) {
            return $denied;
        }

        $cohort = Cohort::find($cohortId);

        if (!$cohort) {
            return $this->errorResponse('Cohort not found', 'NOT_FOUND', 404);
        }

        $enrolment = $this->enrolmentService->removeStudent($cohort, $studentId);

        if (!$enrolment) {
            return $this->errorResponse('Enrolment not found', 'NOT_FOUND', 404);
        }

        return response()->json(['message' => 'Student removed from cohort']);
    }

    /**
     * Return aggregate enrolment statistics for the authenticated school.
     *
     * Provides counts (total students, enrolled, assigned, not assigned, removed)
     * and an array of warnings for conditions that need admin attention. Used by
     * Screen 303 to render the statistics summary panel.
     */
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
     *
     * The credential data comes from the injected CredentialDataProviderInterface
     * (Strategy pattern), which currently returns mock data but will return real
     * data when Karl's credential engine is integrated.
     */
    public function studentDetail(int $studentId): JsonResponse
    {
        // Students can only view their own detail; parents can view their linked children's.
        $user = Auth::user();
        if ($user->role === 'student' && $user->id !== $studentId) {
            return $this->errorResponse('Forbidden', 'FORBIDDEN', 403);
        }
        if ($user->role === 'parent' && !$this->parentCanAccessStudent($user->id, $studentId)) {
            return $this->errorResponse('Forbidden', 'FORBIDDEN', 403);
        }

        $detail = $this->enrolmentService->getStudentDetail($studentId);

        if ($detail === null) {
            return $this->errorResponse('Student not found', 'NOT_FOUND', 404);
        }

        return response()->json($detail);
    }

    /**
     * Export enrolment records as a downloadable CSV file.
     *
     * Supports optional query filters for confidentiality:
     *   - ?cohort_id=1      → only enrolments in that cohort
     *   - ?experience_id=1  → only enrolments in cohorts of that experience
     *   - (no filter)       → all enrolments for the school
     *
     * Streams the CSV directly to the client using php://output to avoid loading
     * the entire dataset into memory. Includes both active and removed enrolments
     * so admins have a complete audit trail for offline analysis.
     *
     * The CSV columns are: student_name, student_email, cohort_name,
     * experience_name, status, enrolled_at, removed_at.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['cohort_id', 'experience_id']);
        $rows = $this->enrolmentService->exportEnrolmentList($filters);

        // streamDownload() sends HTTP headers (Content-Type, Content-Disposition)
        // and then calls the closure to write CSV rows directly to the output stream.
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['student_name', 'student_email', 'cohort_name', 'experience_name', 'status', 'enrolled_at', 'removed_at']);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::sanitizeCsvValue(...), $row));
            }
            fclose($handle);
        }, 'enrolments.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Check whether a parent has a link to the given student.
     */
    private function parentCanAccessStudent(int $parentId, int $studentId): bool
    {
        return DB::table('parent_student_links')
            ->where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->exists();
    }
}
