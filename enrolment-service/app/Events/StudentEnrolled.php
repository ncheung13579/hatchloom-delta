<?php

declare(strict_types=1);

/**
 * StudentEnrolled — Domain event dispatched when a student is enrolled in a cohort.
 *
 * Part of the Observer pattern (SDD Section 6.5) that decouples the core enrolment
 * action from its side effects. Without events, EnrolmentService::enrolStudent()
 * would need to directly call dashboard update logic, teacher notification logic,
 * and credential check logic — making it tightly coupled and hard to extend.
 *
 * WHAT TRIGGERS THIS EVENT:
 *   EnrolmentService::enrolStudent() dispatches this after creating the
 *   CohortEnrolment record and eager-loading relationships.
 *
 * WHAT LISTENERS REACT TO THIS EVENT (defined in EventServiceProvider):
 *  1. UpdateDashboardCounts@handleStudentEnrolled
 *     — Logs the new active enrolment count (when real services are integrated, will notify Dashboard Service)
 *  2. NotifyTeacher@handleStudentEnrolled
 *     — Logs a teacher notification (when real services are integrated, will send email/push notification)
 *  3. TriggerCredentialCheck@handle
 *     — Logs a credential evaluation trigger (when real services are integrated, will call Karl's API)
 *
 * WHY EVENTS DECOUPLE THE ENROLMENT FROM ITS SIDE EFFECTS:
 *  - EnrolmentService only knows it needs to dispatch an event — it does not know
 *    or care what listeners exist
 *  - New side effects can be added by creating a new listener and registering it
 *    in EventServiceProvider — no changes to EnrolmentService needed
 *  - Listeners can be individually tested, enabled/disabled, or made async
 *
 * @see \App\Services\EnrolmentService::enrolStudent()  Where this event is dispatched
 * @see \App\Providers\EventServiceProvider             Where listeners are mapped
 */

namespace App\Events;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a student is enrolled in a cohort.
 *
 * Carries the newly created CohortEnrolment record and the Cohort it belongs
 * to, giving listeners immediate access to all enrolment context (student ID,
 * cohort name, experience, teacher, etc.) without additional queries.
 */
class StudentEnrolled
{
    use Dispatchable;        // Provides the static dispatch() method
    use InteractsWithSockets; // Required by Laravel's event system (even if not broadcasting)
    use SerializesModels;     // Ensures models are serialized/deserialized safely if queued

    /**
     * @param CohortEnrolment $enrolment The newly created enrolment record (with student and cohort.experience loaded)
     * @param Cohort          $cohort    The cohort the student was enrolled into (with teacher and experience loaded)
     */
    public function __construct(
        public readonly CohortEnrolment $enrolment,
        public readonly Cohort $cohort,
    ) {}
}
