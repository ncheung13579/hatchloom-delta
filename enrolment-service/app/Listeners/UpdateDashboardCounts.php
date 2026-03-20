<?php

declare(strict_types=1);

/**
 * UpdateDashboardCounts — Observer pattern listener for dashboard count updates.
 *
 * This listener reacts to both StudentEnrolled and StudentRemoved events to keep
 * the Dashboard Service (port 8001, Screen 300) informed about enrolment changes.
 *
 * WHY THIS LISTENER EXISTS:
 * The Dashboard Service aggregates data from the Experience and Enrolment services.
 * When an enrolment changes, the dashboard's cached counts become stale. This
 * listener is the mechanism for notifying the dashboard of changes.
 *
 * CURRENT BEHAVIOR (mock):
 *   Logs a structured message with the new active enrolment count, cohort details,
 *   and school_id. This demonstrates the Observer pattern is correctly wired.
 *
 * WHEN REAL SERVICES ARE INTEGRATED:
 *   Would send an HTTP request to the Dashboard Service's webhook endpoint to
 *   trigger a cache refresh or real-time update push to the frontend.
 *
 * LISTENER METHOD NAMING:
 * This class uses named handler methods (handleStudentEnrolled, handleStudentRemoved)
 * instead of the default handle() method. This allows a single listener class to
 * respond to multiple event types. The method names are specified in
 * EventServiceProvider using the '@methodName' syntax:
 *   UpdateDashboardCounts::class . '@handleStudentEnrolled'
 *
 * @see \App\Events\StudentEnrolled           Triggers handleStudentEnrolled()
 * @see \App\Events\StudentRemoved            Triggers handleStudentRemoved()
 * @see \App\Providers\EventServiceProvider   Where the listener-to-event mapping is defined
 */

namespace App\Listeners;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listens to enrolment and removal events to log dashboard count changes.
 *
 * When real services are integrated, this listener would send an HTTP notification
 * to the Dashboard Service so it can refresh its cached aggregation counts.
 * Currently, it logs the count-affecting event with enough detail to verify the
 * Observer pattern is wired correctly.
 */
class UpdateDashboardCounts
{
    /**
     * Handle the StudentEnrolled event — log that the active enrolment count increased.
     *
     * Queries the current active enrolment count for the cohort after the new
     * enrolment was created. This count is what the Dashboard Service would use
     * to update its displayed statistics.
     */
    public function handleStudentEnrolled(StudentEnrolled $event): void
    {
        $activeCount = $event->cohort->activeEnrolments()->count();

        Log::info('Dashboard count update: student enrolled', [
            'student_id' => $event->enrolment->student_id,
            'cohort_id' => $event->cohort->id,
            'cohort_name' => $event->cohort->name,
            'school_id' => $event->cohort->school_id,
            'new_active_enrolment_count' => $activeCount,
        ]);
    }

    /**
     * Handle the StudentRemoved event — log that the active enrolment count decreased.
     *
     * Queries the current active enrolment count for the cohort after the
     * removal. Includes the removal timestamp for audit trail purposes.
     */
    public function handleStudentRemoved(StudentRemoved $event): void
    {
        $activeCount = $event->cohort->activeEnrolments()->count();

        Log::info('Dashboard count update: student removed', [
            'student_id' => $event->enrolment->student_id,
            'cohort_id' => $event->cohort->id,
            'cohort_name' => $event->cohort->name,
            'school_id' => $event->cohort->school_id,
            'new_active_enrolment_count' => $activeCount,
            'removed_at' => $event->removedAt->format('Y-m-d H:i:s'),
        ]);
    }
}
