<?php

declare(strict_types=1);

/**
 * NotStartedState — The initial state in the Cohort lifecycle (State pattern).
 *
 * Every cohort begins in the not_started state when created. This represents
 * a cohort that is being set up but has not yet opened for student enrolment.
 *
 * LIFECYCLE POSITION:
 *   [not_started] ──> active ──> completed
 *       ^
 *       You are here
 *
 * ALLOWED TRANSITIONS:
 *  - canActivate() = true  — The cohort can move forward to active
 *  - canComplete() = false — Cannot skip directly to completed; must activate first
 *
 * WHY CAN'T NOT_STARTED SKIP TO COMPLETED?
 * A cohort that was never activated never had students enrolled, so marking it
 * "completed" would be semantically meaningless. The lifecycle enforces that a
 * cohort must run (be active) before it can be considered finished.
 *
 * BUSINESS RULES IN THIS STATE:
 *  - Students CANNOT be enrolled (EnrolmentController checks cohort.status === 'active')
 *  - Cohort metadata (name, dates, capacity, teacher) CAN be modified
 *
 * @see \App\Contracts\CohortState  The interface this implements
 * @see \App\Models\Cohort::state() Where the state object is resolved
 */

namespace App\States;

use App\Contracts\CohortState;

/**
 * Initial state for a newly created cohort.
 *
 * A not_started cohort can be activated but cannot be completed directly.
 */
class NotStartedState implements CohortState
{
    /**
     * A not_started cohort CAN be activated — this is the only valid forward transition.
     */
    public function canActivate(): bool
    {
        return true;
    }

    /**
     * A not_started cohort CANNOT be completed — it must be activated first.
     * Skipping directly to completed would bypass the active enrollment period.
     */
    public function canComplete(): bool
    {
        return false;
    }

    /**
     * The database value for this state: 'not_started'.
     */
    public function status(): string
    {
        return 'not_started';
    }
}
