<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use Illuminate\Support\Facades\Log;

/**
 * Listens to enrolment and removal events to notify the cohort's teacher.
 *
 * In production, this would dispatch a notification (email, in-app, or push)
 * to the teacher assigned to the cohort. For D1, it logs a structured
 * notification message that includes the teacher's identity and the student
 * action, simulating what the real notification payload would contain.
 */
class NotifyTeacher
{
    /**
     * Handle the StudentEnrolled event — notify the teacher of a new student.
     */
    public function handleStudentEnrolled(StudentEnrolled $event): void
    {
        $teacherId = $event->cohort->teacher_id;
        $teacherName = $event->cohort->teacher?->name ?? 'Unknown Teacher';
        $studentName = $event->enrolment->student?->name ?? "Student #{$event->enrolment->student_id}";

        Log::info("Teacher notification: {$studentName} has been enrolled in cohort '{$event->cohort->name}'", [
            'teacher_id' => $teacherId,
            'teacher_name' => $teacherName,
            'student_id' => $event->enrolment->student_id,
            'student_name' => $studentName,
            'cohort_id' => $event->cohort->id,
            'cohort_name' => $event->cohort->name,
            'experience_id' => $event->cohort->experience_id,
            'action' => 'enrolled',
        ]);
    }

    /**
     * Handle the StudentRemoved event — notify the teacher of a student removal.
     */
    public function handleStudentRemoved(StudentRemoved $event): void
    {
        $teacherId = $event->cohort->teacher_id;
        $teacherName = $event->cohort->teacher?->name ?? 'Unknown Teacher';
        $studentName = $event->enrolment->student?->name ?? "Student #{$event->enrolment->student_id}";

        Log::info("Teacher notification: {$studentName} has been removed from cohort '{$event->cohort->name}'", [
            'teacher_id' => $teacherId,
            'teacher_name' => $teacherName,
            'student_id' => $event->enrolment->student_id,
            'student_name' => $studentName,
            'cohort_id' => $event->cohort->id,
            'cohort_name' => $event->cohort->name,
            'experience_id' => $event->cohort->experience_id,
            'action' => 'removed',
            'removed_at' => $event->removedAt->format('Y-m-d H:i:s'),
        ]);
    }
}
