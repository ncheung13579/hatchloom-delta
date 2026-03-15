<?php

declare(strict_types=1);

/**
 * EventServiceProvider — Defines the Observer pattern event-to-listener mappings.
 *
 * This provider tells Laravel which listeners should be invoked when each domain
 * event is dispatched. It is the central registry for the Observer pattern in the
 * Enrolment Service.
 *
 * HOW THE OBSERVER PATTERN IS WIRED:
 *
 *   StudentEnrolled (dispatched by EnrolmentService::enrolStudent)
 *     |
 *     +-- UpdateDashboardCounts@handleStudentEnrolled  (logs dashboard count change)
 *     +-- NotifyTeacher@handleStudentEnrolled          (logs teacher notification)
 *     +-- TriggerCredentialCheck                       (logs credential evaluation trigger)
 *
 *   StudentRemoved (dispatched by EnrolmentService::removeStudent)
 *     |
 *     +-- UpdateDashboardCounts@handleStudentRemoved   (logs dashboard count change)
 *     +-- NotifyTeacher@handleStudentRemoved           (logs teacher notification)
 *
 * LISTENER METHOD ROUTING:
 * The '@methodName' syntax (e.g., UpdateDashboardCounts::class . '@handleStudentEnrolled')
 * tells Laravel to call a specific method on the listener class instead of the default
 * handle() method. This allows one listener class to handle multiple event types.
 * TriggerCredentialCheck uses the default handle() method since it only handles one event.
 *
 * WHY EVENTS + LISTENERS (instead of direct method calls):
 *  - Decoupling: EnrolmentService dispatches events without knowing what reacts
 *  - Extensibility: Add new side effects by creating a listener and registering it here
 *  - Testability: Each listener can be tested independently
 *  - Future: Listeners can be made async (ShouldQueue) without changing the dispatcher
 *
 * @see \App\Events\StudentEnrolled          Event for new enrolments
 * @see \App\Events\StudentRemoved           Event for student removals
 * @see \App\Listeners\UpdateDashboardCounts Listener for dashboard count updates
 * @see \App\Listeners\NotifyTeacher         Listener for teacher notifications
 * @see \App\Listeners\TriggerCredentialCheck Listener for credential evaluation
 */

namespace App\Providers;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use App\Listeners\NotifyTeacher;
use App\Listeners\TriggerCredentialCheck;
use App\Listeners\UpdateDashboardCounts;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Maps domain events to their listeners (Observer pattern).
 *
 * StudentEnrolled triggers three listeners:
 *  - UpdateDashboardCounts: refreshes aggregation data for Screen 300
 *  - NotifyTeacher: alerts the cohort's assigned teacher
 *  - TriggerCredentialCheck: initiates credential evaluation for the student
 *
 * StudentRemoved triggers two listeners:
 *  - UpdateDashboardCounts: refreshes aggregation data for Screen 300
 *  - NotifyTeacher: alerts the cohort's assigned teacher
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event-to-listener mappings for the enrolment service.
     *
     * Each key is an event class, and its value is an array of listener references.
     * The '@methodName' suffix routes to a specific handler method on the listener.
     * Without it, Laravel calls the default handle() method.
     *
     * @var array<class-string, array<int, class-string|string>>
     */
    protected $listen = [
        // When a student is enrolled, notify the dashboard, teacher, and credential engine
        StudentEnrolled::class => [
            UpdateDashboardCounts::class . '@handleStudentEnrolled',
            NotifyTeacher::class . '@handleStudentEnrolled',
            TriggerCredentialCheck::class, // Uses default handle() method
        ],
        // When a student is removed, notify the dashboard and teacher
        // (no credential check needed on removal)
        StudentRemoved::class => [
            UpdateDashboardCounts::class . '@handleStudentRemoved',
            NotifyTeacher::class . '@handleStudentRemoved',
        ],
    ];
}
