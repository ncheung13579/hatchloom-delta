<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StudentEnrolled;
use Illuminate\Support\Facades\Log;

/**
 * Listens to the StudentEnrolled event to trigger credential evaluation.
 *
 * When a student is enrolled in a cohort, the credential engine (owned by
 * Karl / Role B) should evaluate whether the student's prior credentials
 * satisfy any prerequisites for the experience's courses. For D1, this
 * listener logs the trigger; in production it would call Karl's credential
 * evaluation API endpoint.
 */
class TriggerCredentialCheck
{
    /**
     * Handle the StudentEnrolled event — log that credential check should run.
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
