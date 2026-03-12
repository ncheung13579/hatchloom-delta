<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;
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
    public function __construct(
        private readonly CredentialDataProviderInterface $credentialProvider
    ) {}
    /**
     * Build a paginated overview of all students and their cohort assignments.
     *
     * Assignment status logic:
     *  - "assigned"     — student has at least one enrolment with status=enrolled in an active cohort
     *  - "removed"      — student has enrolments but ALL of them have status=removed
     *  - "not_assigned" — student has no enrolments, or none that qualify as assigned/removed
     *
     * Supports optional filters to narrow results:
     *  - experience_id: only students enrolled in cohorts of that experience
     *  - cohort_id: only students enrolled in that specific cohort
     *  - grade: no-op for D1 (the users table does not yet have a grade column)
     */
    public function getEnrolmentOverview(?string $search = null, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $schoolId = Auth::user()->school_id;

        $query = User::where('school_id', $schoolId)
            ->where('role', 'student');

        if ($search) {
            $searchLower = mb_strtolower($search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]);
        }

        // Filter by experience_id — find students who have an enrolment in any
        // cohort belonging to the given experience.
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

        // Filter by cohort_id — find students who have an enrolment in that cohort.
        if (isset($filters['cohort_id'])) {
            $studentIds = CohortEnrolment::where('cohort_id', $filters['cohort_id'])
                ->pluck('student_id')
                ->unique();

            $query->whereIn('id', $studentIds);
        }

        // Grade filtering will be available when the users table includes a grade column.
        // For D1 the parameter is accepted but not applied.

        $students = $query->paginate($perPage);

        $students->getCollection()->transform(function (User $student) {
            $enrolments = CohortEnrolment::where('student_id', $student->id)
                ->with(['cohort.experience'])
                ->get();

            $assignments = $enrolments->map(function (CohortEnrolment $enrolment) {
                return [
                    'cohort_id' => $enrolment->cohort_id,
                    'cohort_name' => $enrolment->cohort?->name,
                    'experience_name' => $enrolment->cohort?->experience?->name,
                    'status' => $enrolment->status,
                    'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
                ];
            });

            $hasActiveEnrolment = $enrolments->contains(function (CohortEnrolment $e) {
                return $e->status === 'enrolled' && $e->cohort && $e->cohort->status === 'active';
            });

            $allRemoved = $enrolments->isNotEmpty() && $enrolments->every(fn($e) => $e->status === 'removed');

            if ($hasActiveEnrolment) {
                $assignmentStatus = 'assigned';
            } elseif ($allRemoved) {
                $assignmentStatus = 'removed';
            } else {
                $assignmentStatus = 'not_assigned';
            }

            return [
                'student_id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'cohort_assignments' => $assignments,
                'assignment_status' => $assignmentStatus,
            ];
        });

        return $students;
    }

    public function enrolStudent(Cohort $cohort, int $studentId): CohortEnrolment
    {
        $enrolment = CohortEnrolment::create([
            'cohort_id' => $cohort->id,
            'student_id' => $studentId,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Load relationships so listeners have access to student/cohort details
        // without issuing additional queries.
        $enrolment->load(['student', 'cohort.experience']);
        $cohort->load(['teacher', 'experience']);

        StudentEnrolled::dispatch($enrolment, $cohort);

        return $enrolment;
    }

    public function removeStudent(Cohort $cohort, int $studentId): ?CohortEnrolment
    {
        $enrolment = CohortEnrolment::where('cohort_id', $cohort->id)
            ->where('student_id', $studentId)
            ->where('status', 'enrolled')
            ->first();

        if (!$enrolment) {
            return null;
        }

        $enrolment->remove();

        // Load relationships so listeners have access to student/cohort details.
        $enrolment->load(['student', 'cohort.experience']);
        $cohort->load(['teacher', 'experience']);

        StudentRemoved::dispatch($enrolment, $cohort, $enrolment->removed_at);

        return $enrolment;
    }

    /**
     * Calculate school-wide enrolment statistics and generate warnings.
     *
     * Counts enrolled vs. unassigned students and checks cohort capacity.
     * Warning generation rules:
     *  - "unassigned_students" (severity=warning) — triggered when any students
     *    lack an active cohort enrolment
     *  - "capacity_warning" (severity=info) — triggered when an active cohort
     *    reaches 90% or more of its defined capacity
     */
    public function calculateStatistics(): array
    {
        $schoolId = Auth::user()->school_id;

        $totalStudents = User::where('school_id', $schoolId)
            ->where('role', 'student')
            ->count();

        $enrolledStudentIds = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->where('status', 'enrolled')
            ->pluck('student_id')
            ->unique();

        $activeStudentIds = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId)->where('status', 'active');
        })->where('status', 'enrolled')
            ->pluck('student_id')
            ->unique();

        $removedCount = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->where('status', 'removed')->count();

        $assigned = $activeStudentIds->count();
        $notAssigned = $totalStudents - $assigned;

        $warnings = [];
        if ($notAssigned > 0) {
            $warnings[] = [
                'type' => 'unassigned_students',
                'message' => "{$notAssigned} students are not assigned to any active cohort",
                'severity' => 'warning',
            ];
        }

        // Check capacity warnings
        $cohorts = Cohort::where('school_id', $schoolId)
            ->where('status', 'active')
            ->withCount(['activeEnrolments'])
            ->get();

        foreach ($cohorts as $cohort) {
            if ($cohort->capacity && $cohort->active_enrolments_count >= $cohort->capacity * 0.9) {
                $warnings[] = [
                    'type' => 'capacity_warning',
                    'message' => "{$cohort->name} is at " . round(($cohort->active_enrolments_count / $cohort->capacity) * 100) . "% capacity ({$cohort->active_enrolments_count}/{$cohort->capacity})",
                    'severity' => 'info',
                ];
            }
        }

        return [
            'total_students' => $totalStudents,
            'enrolled' => $enrolledStudentIds->count(),
            'assigned' => $assigned,
            'not_assigned' => $notAssigned,
            'removed' => $removedCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a flat list of all enrolment records for CSV export.
     *
     * Returns every enrolment (including removed ones) scoped to the
     * authenticated user's school, with student and cohort details denormalized
     * into each row for direct CSV serialization.
     */
    public function exportEnrolmentList(): array
    {
        $schoolId = Auth::user()->school_id;

        $enrolments = CohortEnrolment::whereHas('cohort', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->with(['student', 'cohort.experience'])->get();

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
     * mock credential summary that will be replaced with real data from Karl's
     * credential engine once it is available.
     *
     * @return array<string, mixed>|null Null when the student does not exist or is outside the admin's school.
     */
    public function getStudentDetail(int $studentId): ?array
    {
        $schoolId = Auth::user()->school_id;

        $student = User::where('id', $studentId)
            ->where('school_id', $schoolId)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return null;
        }

        $enrolments = CohortEnrolment::where('student_id', $student->id)
            ->with(['cohort.experience'])
            ->get();

        $enrolmentList = $enrolments->map(function (CohortEnrolment $enrolment) {
            return [
                'cohort_id' => $enrolment->cohort_id,
                'cohort_name' => $enrolment->cohort?->name,
                'experience_name' => $enrolment->cohort?->experience?->name,
                'status' => $enrolment->status,
                'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
            ];
        });

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
