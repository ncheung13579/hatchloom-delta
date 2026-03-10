<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class EnrolmentService
{
    public function getEnrolmentOverview(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        $schoolId = Auth::user()->school_id;

        $query = User::where('school_id', $schoolId)
            ->where('role', 'student');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

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
        return CohortEnrolment::create([
            'cohort_id' => $cohort->id,
            'student_id' => $studentId,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);
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
        return $enrolment;
    }

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
}
