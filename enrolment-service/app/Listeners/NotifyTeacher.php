<?php

declare(strict_types=1);

/**
 * NotifyTeacher — Observer pattern listener for teacher notifications.
 *
 * This listener reacts to both StudentEnrolled and StudentRemoved events to
 * notify the teacher assigned to the cohort about roster changes.
 *
 * WHY THIS LISTENER EXISTS:
 * Teachers need to know when students join or leave their cohorts so they can
 * adjust their teaching plans, welcome new students, or follow up on removals.
 * By using the Observer pattern, the notification logic is decoupled from the
 * enrolment action — EnrolmentService does not know or care that teachers are
 * being notified.
 *
 * CURRENT BEHAVIOR (mock):
 *   Logs a structured notification message with teacher identity, student
 *   identity, cohort details, and the action (enrolled/removed). This simulates
 *   the real notification payload and verifies the Observer wiring.
 *
 * WHEN REAL SERVICES ARE INTEGRATED:
 *   Would dispatch a Laravel Notification (email, in-app, or push) to the
 *   teacher user. Could also be made async via ShouldQueue to avoid blocking
 *   the HTTP response.
 *
 * LISTENER METHOD NAMING:
 * Like UpdateDashboardCounts, this uses named handler methods to handle
 * multiple event types from a single class. The '@methodName' syntax in
 * EventServiceProvider routes each event to the correct method.
 *
 * @see \App\Events\StudentEnrolled           Triggers handleStudentEnrolled()
 * @see \App\Events\StudentRemoved            Triggers handleStudentRemoved()
 * @see \App\Providers\EventServiceProvider   Where the listener-to-event mapping is defined
 */

namespace App\Listeners;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use Illuminate\Support\Facades\Log;

/**
 * Listens to enrolment and removal events to notify the cohort's teacher.
 *
 * In production, this would dispatch a notification (email, in-app, or push)
 * to the teacher assigned to the cohort. Currently, it logs a structured
 * notification message that includes the teacher's identity and the student
 * action, simulating what the real notification payload would contain.
 */
class NotifyTeacher
{
    /**
     * Handle the StudentEnrolled event — notify the teacher of a new student.
     *
     * Resolves teacher and student names with null-safe fallbacks to prevent
     * errors if relationships are missing (e.g., cohort has no assigned teacher).
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
     *
     * Includes the removal timestamp so the teacher knows when the removal occurred.
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
