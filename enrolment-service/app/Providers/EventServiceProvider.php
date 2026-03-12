<?php

declare(strict_types=1);

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
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        StudentEnrolled::class => [
            UpdateDashboardCounts::class . '@handleStudentEnrolled',
            NotifyTeacher::class . '@handleStudentEnrolled',
            TriggerCredentialCheck::class,
        ],
        StudentRemoved::class => [
            UpdateDashboardCounts::class . '@handleStudentRemoved',
            NotifyTeacher::class . '@handleStudentRemoved',
        ],
    ];
}
