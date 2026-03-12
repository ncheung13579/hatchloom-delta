<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listens to enrolment and removal events to log dashboard count changes.
 *
 * In production (D2+), this listener would send an HTTP notification to the
 * Dashboard Service so it can refresh its cached aggregation counts. For D1,
 * it logs the count-affecting event with enough detail to verify the Observer
 * pattern is wired correctly.
 */
class UpdateDashboardCounts
{
    /**
     * Handle the StudentEnrolled event — log that the active enrolment count increased.
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
