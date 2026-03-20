<?php

declare(strict_types=1);

/**
 * StudentRemoved — Domain event dispatched when a student is removed from a cohort.
 *
 * Part of the Observer pattern (SDD Section 6.5) that decouples the removal action
 * from its side effects. This event is the counterpart to StudentEnrolled.
 *
 * WHAT TRIGGERS THIS EVENT:
 *   EnrolmentService::removeStudent() dispatches this after soft-removing the
 *   CohortEnrolment record (setting status='removed' and removed_at timestamp)
 *   and eager-loading relationships.
 *
 * WHAT LISTENERS REACT TO THIS EVENT (defined in EventServiceProvider):
 *  1. UpdateDashboardCounts@handleStudentRemoved
 *     — Logs the updated active enrolment count (when real services are integrated, will notify Dashboard Service)
 *  2. NotifyTeacher@handleStudentRemoved
 *     — Logs a teacher notification (when real services are integrated, will send email/push notification)
 *
 * Note: TriggerCredentialCheck does NOT listen for this event because credential
 * evaluation is only meaningful at enrolment time, not removal time.
 *
 * This event carries the removal timestamp as a separate parameter (rather than
 * reading it from the enrolment model) to ensure listeners receive the exact
 * timestamp that was set during the removal operation, even if the model is
 * modified between dispatch and handling.
 *
 * @see \App\Services\EnrolmentService::removeStudent()  Where this event is dispatched
 * @see \App\Providers\EventServiceProvider              Where listeners are mapped
 */

namespace App\Events;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a student is removed from a cohort.
 *
 * Carries the updated CohortEnrolment (now with status=removed), the Cohort,
 * and the exact removal timestamp so listeners can log or react to the removal
 * with full context.
 */
class StudentRemoved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param CohortEnrolment    $enrolment The updated enrolment record (status='removed', with relationships loaded)
     * @param Cohort             $cohort    The cohort the student was removed from (with teacher and experience loaded)
     * @param \DateTimeInterface $removedAt The exact timestamp when the removal occurred
     */
    public function __construct(
        public readonly CohortEnrolment $enrolment,
        public readonly Cohort $cohort,
        public readonly \DateTimeInterface $removedAt,
    ) {}
}
