<?php

declare(strict_types=1);

/**
 * TriggerCredentialCheck — Observer pattern listener for credential evaluation.
 *
 * This listener reacts ONLY to the StudentEnrolled event (not StudentRemoved).
 * When a student is enrolled in a cohort, the credential engine should evaluate
 * whether the student's prior credentials satisfy any prerequisites for the
 * experience's courses.
 *
 * WHY ONLY ON ENROLMENT (NOT REMOVAL):
 * Credential evaluation is forward-looking: "does this student have the
 * prerequisites to succeed in this experience?" When a student is removed,
 * there is nothing to evaluate — they are no longer participating.
 *
 * CURRENT BEHAVIOR (mock):
 *   Logs a structured message indicating which student, experience, and cohort
 *   triggered the credential check. This verifies the Observer wiring.
 *
 * WHEN REAL SERVICES ARE INTEGRATED:
 *   Would make an HTTP call to Karl's credential evaluation API endpoint,
 *   passing the student_id and experience_id. The response would indicate
 *   whether the student meets prerequisites, and if not, could trigger a
 *   warning in the admin dashboard.
 *
 * LISTENER METHOD NAMING:
 * Unlike UpdateDashboardCounts and NotifyTeacher, this listener uses the
 * default handle() method because it only handles one event type. In
 * EventServiceProvider, it is registered without the '@methodName' suffix.
 *
 * @see \App\Events\StudentEnrolled           The only event this listens to
 * @see \App\Providers\EventServiceProvider   Where the listener-to-event mapping is defined
 * @see \App\Contracts\CredentialDataProviderInterface  Related: the Strategy pattern provider
 */

namespace App\Listeners;

use App\Events\StudentEnrolled;
use Illuminate\Support\Facades\Log;

/**
 * Listens to the StudentEnrolled event to trigger credential evaluation.
 *
 * When a student is enrolled in a cohort, the credential engine (owned by
 * Karl / Role B) should evaluate whether the student's prior credentials
 * satisfy any prerequisites for the experience's courses. Currently, this
 * listener logs the trigger; when real services are integrated it would call
 * Karl's credential evaluation API endpoint.
 */
class TriggerCredentialCheck
{
    /**
     * Handle the StudentEnrolled event — log that credential check should run.
     *
     * Resolves student and experience names with null-safe fallbacks, then logs
     * a structured message with all the context Karl's API would need.
     */
    public function handle(StudentEnrolled $event): void
    {
        $studentName = $event->enrolment->student?->name ?? "Student #{$event->enrolment->student_id}";
        $experienceName = $event->cohort->experience?->name ?? "Experience #{$event->cohort->experience_id}";

        Log::info("Credential check triggered: evaluating credentials for {$studentName} enrolling in '{$experienceName}' via cohort '{$event->cohort->name}'", [
            'student_id' => $event->enrolment->student_id,
            'student_name' => $studentName,
            'cohort_id' => $event->cohort->id,
            'cohort_name' => $event->cohort->name,
            'experience_id' => $event->cohort->experience_id,
            'experience_name' => $experienceName,
            'school_id' => $event->cohort->school_id,
        ]);
    }
}
