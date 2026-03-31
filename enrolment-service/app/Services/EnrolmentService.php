<?php

declare(strict_types=1);

/**
 * EnrolmentService — Business logic layer for student enrolment operations.
 *
 * This is the most complex service in the Enrolment Service microservice. It
 * implements Screen 303 (Enrolment) functionality:
 *  - Paginated student overview with assignment status computation
 *  - Enrol and remove operations with event dispatching (Observer pattern)
 *  - School-wide statistics with automated warning generation
 *  - Student drill-down detail with credential data (Strategy pattern)
 *  - CSV export of all enrolment records
 *
 * Design patterns present:
 *  - Repository pattern: Acts as the boundary between EnrolmentController and
 *    the Eloquent models. Controllers validate input; this service executes logic.
 *  - Observer pattern: enrolStudent() and removeStudent() dispatch domain events
 *    (StudentEnrolled, StudentRemoved) that decouple the core action from its
 *    side effects (dashboard updates, teacher notifications, credential checks).
 *  - Strategy pattern: Depends on CredentialDataProviderInterface (constructor
 *    injection) so the credential data source can be swapped without changing
 *    this service. With the mock provider, placeholder data is returned; when
 *    real services are integrated, it will query Karl's credential engine.
 *
 * @see \App\Http\Controllers\EnrolmentController  The controller that uses this service
 * @see \App\Contracts\CredentialDataProviderInterface  Strategy pattern dependency
 * @see \App\Events\StudentEnrolled                 Event dispatched on enrolment
 * @see \App\Events\StudentRemoved                  Event dispatched on removal
 */

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;
use App\Enums\CohortStatus;
use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Handles student enrolment operations and school-wide enrolment statistics.
 *
 * Serves as the business logic layer for Screen 303 (Enrolment). Provides
 * student assignment overview with pagination, enrol/remove operations,
 * aggregate statistics with warning generation, and CSV export. Credential
 * data is sourced from an injected CredentialDataProviderInterface, allowing
 * the mock implementation to be swapped for real data when available.
 */
class EnrolmentService
{
    /**
     * Constructor injection of the credential data provider (Strategy pattern).
     *
     * Laravel's container resolves this to MockCredentialDataProvider via the
     * binding in AppServiceProvider. When Karl's credential engine is ready,
     * only the binding needs to change — this service is unaffected.
     */
    public function __construct(
        private readonly CredentialDataProviderInterface $credentialProvider
    ) {}
    /**
     * Build a paginated overview of all students and their cohort assignments.
     *
     * This is the main data source for Screen 303's student table. For each
     * student, it computes an assignment_status that summarizes their enrolment
     * state across all cohorts:
     *
     * Assignment status logic:
     *  - "assigned"     — student has at least one enrolment with status=enrolled in an active cohort
     *  - "removed"      — student has enrolments but ALL of them have status=removed
     *  - "not_assigned" — student has no enrolments, or none that qualify as assigned/removed
     *
     * Supports optional filters to narrow results:
     *  - experience_id: only students enrolled in cohorts of that experience
     *  - cohort_id: only students enrolled in that specific cohort
     *  - student_id: return only the specified student
     *  - grade: currently a no-op (the users table does not yet have a grade column)
     *
     * Note: The student query is scoped to school_id and role='student' to ensure
     * tenant isolation and exclude admin/teacher users from the student list.
     */
    public function getEnrolmentOverview(?string $search = null, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $schoolId = Auth::user()->school_id;

        // Base query: all students in the authenticated admin's school
        $query = User::where('school_id', $schoolId)
            ->where('role', 'student');

        // Case-insensitive name search using LOWER() for PostgreSQL compatibility
        if ($search) {
            $searchLower = mb_strtolower($search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]);
        }

        // Filter by experience_id — find students who have an enrolment in any
        // cohort belonging to the given experience. Uses withoutGlobalScopes()
        // on the Cohort query because we manually apply school_id filtering,
        // and the SchoolScope would conflict with the subquery context.
        if (isset($filters['experience_id'])) {
            $cohortIds = Cohort::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('experience_id', $filters['experience_id'])
                ->pluck('id');

            $studentIds = CohortEnrolment::whereIn('cohort_id', $cohortIds)
                ->pluck('student_id')
                ->unique();

            $query->whereIn('id', $studentIds);
        }

        // Filter by cohort_id — find students who have an enrolment in that specific cohort.
        if (isset($filters['cohort_id'])) {
            $studentIds = CohortEnrolment::where('cohort_id', $filters['cohort_id'])
                ->pluck('student_id')
                ->unique();

            $query->whereIn('id', $studentIds);
        }

        // Filter by student_id — return only the specified student.
        if (isset($filters['student_id'])) {
            $query->where('id', $filters['student_id']);
        }

        // Filter by student_ids — return only the specified students (used for parent
        // role scoping where a parent may be linked to multiple children).
        if (isset($filters['student_ids'])) {
            $query->whereIn('id', $filters['student_ids']);
        }

        // Grade filtering will be available when the users table includes a grade column.
        // Grade filtering is accepted but not yet applied (awaiting users table migration).

        $students = $query->paginate($perPage);

        // Batch-load enrolments for students on this page in a single query,
        // avoiding the N+1 pattern of querying enrolments per student individually.
        // When filtered by experience_id or cohort_id, scope the enrolments to match
        // so that the response only includes assignments relevant to the filter context.
        $studentIds = $students->getCollection()->pluck('id');
        $enrolmentQuery = CohortEnrolment::whereIn('student_id', $studentIds)
            ->with(['cohort.experience']);

        if (isset($filters['experience_id'])) {
            $enrolmentQuery->whereHas('cohort', function ($q) use ($filters) {
                $q->where('experience_id', $filters['experience_id']);
            });
        }

        if (isset($filters['cohort_id'])) {
            $enrolmentQuery->where('cohort_id', $filters['cohort_id']);
        }

        $allEnrolments = $enrolmentQuery->get()->groupBy('student_id');

        $students->getCollection()->transform(function (User $student) use ($allEnrolments) {
            return $this->transformStudentWithAssignments($student, $allEnrolments->get($student->id, collect()));
        });

        return $students;
    }

    /**
     * Transform a student model into the enriched response with cohort assignments.
     *
     * Fetches all enrolments (active and removed) for the student, maps each to a
     * flat array with cohort/experience names, and computes the aggregate
     * assignment_status that summarises the student's overall enrolment state.
     */
    private function transformStudentWithAssignments(User $student, \Illuminate\Support\Collection $enrolments): array
    {
        $assignments = $enrolments->map(fn(CohortEnrolment $e) => [
            'cohort_id' => $e->cohort_id,
            'cohort_name' => $e->cohort?->name,
            'experience_id' => $e->cohort?->experience_id,
            'experience_name' => $e->cohort?->experience?->name,
            'status' => $e->status,
            'enrolled_at' => $e->enrolled_at?->toIso8601String(),
        ]);

        return [
            'student_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'cohort_assignments' => $assignments,
            'assignment_status' => $this->determineAssignmentStatus($enrolments),
        ];
    }

    /**
     * Determine a student's overall assignment status from their enrolments.
     *
     * Returns 'assigned' if the student has an active enrolment in an active cohort,
     * 'removed' if all enrolments are removed, or 'not_assigned' otherwise.
     */
    private function determineAssignmentStatus(\Illuminate\Support\Collection $enrolments): string
    {
        $hasActiveEnrolment = $enrolments->contains(function (CohortEnrolment $e) {
            return $e->status === 'enrolled' && $e->cohort && $e->cohort->status === CohortStatus::ACTIVE->value;
        });

        if ($hasActiveEnrolment) {
            return 'assigned';
        }

        $allRemoved = $enrolments->isNotEmpty() && $enrolments->every(fn($e) => $e->status === 'removed');

        return $allRemoved ? 'removed' : 'not_assigned';
    }

    /**
     * Enrol a student into a cohort and dispatch the StudentEnrolled event.
     *
     * Creates the CohortEnrolment record with status='enrolled' and the current
     * timestamp. Then dispatches the StudentEnrolled event, which triggers the
     * Observer pattern listeners:
     *  - UpdateDashboardCounts: logs the new active enrolment count
     *  - NotifyTeacher: logs a notification for the cohort's teacher
     *  - TriggerCredentialCheck: logs that credential evaluation should run
     *
     * Relationships are eager-loaded before dispatching the event so that
     * listeners can access student/cohort/experience/teacher details without
     * issuing additional database queries.
     *
     * @param Cohort $cohort    The cohort to enrol the student into
     * @param int    $studentId The student's user ID
     * @return CohortEnrolment  The newly created enrolment record
     */
    public function enrolStudent(Cohort $cohort, int $studentId): CohortEnrolment
    {
        $enrolment = CohortEnrolment::create([
            'cohort_id' => $cohort->id,
            'student_id' => $studentId,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Eager-load relationships so listeners have access to student/cohort details
        // without issuing additional queries. This is a performance optimization.
        $enrolment->load(['student', 'cohort.experience']);
        $cohort->load(['teacher', 'experience']);

        // Dispatch the domain event — this triggers all registered listeners
        // via the EventServiceProvider mappings (Observer pattern).
        StudentEnrolled::dispatch($enrolment, $cohort);

        return $enrolment;
    }

    /**
     * Soft-remove a student from a cohort and dispatch the StudentRemoved event.
     *
     * Finds the active enrolment (status='enrolled') for the given student and
     * cohort, then calls the CohortEnrolment::remove() method which sets
     * status='removed' and records the removed_at timestamp.
     *
     * Returns null if there is no active enrolment to remove (the student was
     * never enrolled or was already removed).
     *
     * @param Cohort $cohort    The cohort to remove the student from
     * @param int    $studentId The student's user ID
     * @return CohortEnrolment|null The updated enrolment record, or null if not found
     */
    public function removeStudent(Cohort $cohort, int $studentId): ?CohortEnrolment
    {
        // Only look for active enrolments — already-removed enrolments are not removable again
        $enrolment = CohortEnrolment::where('cohort_id', $cohort->id)
            ->where('student_id', $studentId)
            ->where('status', 'enrolled')
            ->first();

        if (!$enrolment) {
            return null;
        }

        // Soft-remove: sets status='removed' and removed_at=now()
        $enrolment->remove();

        // Eager-load relationships for the event listeners
        $enrolment->load(['student', 'cohort.experience']);
        $cohort->load(['teacher', 'experience']);

        // Dispatch the domain event with the removal timestamp
        StudentRemoved::dispatch($enrolment, $cohort, $enrolment->removed_at);

        return $enrolment;
    }

    /**
     * Calculate school-wide enrolment statistics and generate warnings.
     *
     * This powers the statistics panel on Screen 303. It computes five metrics:
     *  - total_students: all students in the school
     *  - enrolled: students with at least one active enrolment (any cohort status)
     *  - assigned: students with at least one active enrolment in an active cohort
     *  - not_assigned: total_students - assigned (students needing attention)
     *  - removed: total count of removed enrolment records
     *
     * Warning generation rules:
     *  - "unassigned_students" (severity=warning) — triggered when any students
     *    lack an active cohort enrolment. This alerts the admin that some students
     *    are not participating in any running cohort.
     *  - "capacity_warning" (severity=info) — triggered when an active cohort
     *    reaches 90% or more of its defined capacity. This gives the admin
     *    advance notice before a cohort fills up completely.
     */
    public function calculateStatistics(): array
    {
        $schoolId = Auth::user()->school_id;

        // Count all students in the school (regardless of enrolment status)
        $totalStudents = User::where('school_id', $schoolId)
            ->where('role', 'student')
            ->count();

        // Students with at least one enrolled status in any cohort (any cohort status)
        $enrolledStudentIds = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->where('status', 'enrolled')
            ->pluck('student_id')
            ->unique();

        // Students with at least one enrolled status in an ACTIVE cohort specifically
        $activeStudentIds = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId)->where('status', CohortStatus::ACTIVE->value);
        })->where('status', 'enrolled')
            ->pluck('student_id')
            ->unique();

        // Total removed enrolment records (not unique students — one student can
        // be removed from multiple cohorts)
        $removedCount = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->where('status', 'removed')->count();

        $assigned = $activeStudentIds->count();
        $notAssigned = $totalStudents - $assigned;

        return [
            'total_students' => $totalStudents,
            'enrolled' => $enrolledStudentIds->count(),
            'assigned' => $assigned,
            'not_assigned' => $notAssigned,
            'removed' => $removedCount,
            'warnings' => $this->generateWarnings($schoolId, $notAssigned),
        ];
    }

    /**
     * Generate actionable warnings for the statistics panel.
     *
     * Checks for unassigned students and cohorts nearing capacity.
     * Separated from calculateStatistics() to isolate warning logic.
     */
    private function generateWarnings(int $schoolId, int $notAssigned): array
    {
        $warnings = [];

        if ($notAssigned > 0) {
            $warnings[] = [
                'type' => 'unassigned_students',
                'message' => "{$notAssigned} students are not assigned to any active cohort",
                'severity' => 'warning',
            ];
        }

        $cohorts = Cohort::where('school_id', $schoolId)
            ->where('status', CohortStatus::ACTIVE->value)
            ->withCount(['activeEnrolments'])
            ->get();

        foreach ($cohorts as $cohort) {
            // 90% threshold: warn before a cohort is completely full so admins
            // have time to open a new cohort or increase capacity.
            if ($cohort->capacity && $cohort->active_enrolments_count >= $cohort->capacity * 0.9) {
                $warnings[] = [
                    'type' => 'capacity_warning',
                    'message' => "{$cohort->name} is at " . round(($cohort->active_enrolments_count / $cohort->capacity) * 100) . "% capacity ({$cohort->active_enrolments_count}/{$cohort->capacity})",
                    'severity' => 'info',
                ];
            }
        }

        return $warnings;
    }

    /**
     * Build a flat list of all enrolment records for CSV export.
     *
     * Returns every enrolment (including removed ones) scoped to the
     * authenticated user's school, with student and cohort details denormalized
     * into each row for direct CSV serialization. The denormalization means
     * each row is self-contained — no joins needed when writing to CSV.
     *
     * The whereHas('cohort') clause ensures school scoping by checking the
     * cohort's school_id, since CohortEnrolment itself does not have a
     * school_id column.
     */
    public function exportEnrolmentList(array $filters = []): array
    {
        $schoolId = Auth::user()->school_id;

        $query = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->with(['student', 'cohort.experience']);

        // Filter by specific cohort
        if (isset($filters['cohort_id'])) {
            $query->where('cohort_id', $filters['cohort_id']);
        }

        // Filter by experience (all cohorts belonging to that experience)
        if (isset($filters['experience_id'])) {
            $query->whereHas('cohort', function ($q) use ($filters) {
                $q->where('experience_id', $filters['experience_id']);
            });
        }

        $enrolments = $query->get();

        $rows = [];
        foreach ($enrolments as $enrolment) {
            $rows[] = [
                'student_name' => $enrolment->student?->name,
                'student_email' => $enrolment->student?->email,
                'cohort_name' => $enrolment->cohort?->name,
                'experience_name' => $enrolment->cohort?->experience?->name,
                'status' => $enrolment->status,
                'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
                'removed_at' => $enrolment->removed_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Retrieve a single student's full enrolment picture for the drill-down view.
     *
     * Returns all cohort assignments with experience names so the school admin
     * can inspect a student's history without leaving Screen 303. Includes a
     * credential summary from the injected CredentialDataProviderInterface
     * (Strategy pattern) — currently mock data, will be real when Karl's
     * credential engine is integrated.
     *
     * Security: Verifies the student belongs to the authenticated admin's school
     * and has role='student' before returning any data.
     *
     * @return array<string, mixed>|null Null when the student does not exist or is outside the admin's school.
     */
    public function getStudentDetail(int $studentId): ?array
    {
        $schoolId = Auth::user()->school_id;

        // Verify the student exists, belongs to this school, and is actually a student
        $student = User::where('id', $studentId)
            ->where('school_id', $schoolId)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return null;
        }

        // Load all enrolments (active and removed) with cohort and experience data
        $enrolments = CohortEnrolment::where('student_id', $student->id)
            ->with(['cohort.experience'])
            ->get();

        $enrolmentList = $enrolments->map(function (CohortEnrolment $enrolment) {
            return [
                'cohort_id' => $enrolment->cohort_id,
                'cohort_name' => $enrolment->cohort?->name,
                'experience_id' => $enrolment->cohort?->experience_id,
                'experience_name' => $enrolment->cohort?->experience?->name,
                'status' => $enrolment->status,
                'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
            ];
        });

        // Fetch credential data from the injected provider (Strategy pattern).
        // With the mock provider this returns placeholder credential data.
        $credentials = $this->credentialProvider->getStudentCredentialSummary($studentId);

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'grade' => $student->grade ?? null,
            ],
            'enrolments' => $enrolmentList->toArray(),
            'credentials' => $credentials,
        ];
    }
}
