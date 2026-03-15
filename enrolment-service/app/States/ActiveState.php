<?php

declare(strict_types=1);

/**
 * ActiveState — The running state in the Cohort lifecycle (State pattern).
 *
 * A cohort enters the active state when an admin explicitly activates it.
 * This represents a cohort that is currently running — students can be
 * enrolled and removed, and the cohort is counted in "active" statistics.
 *
 * LIFECYCLE POSITION:
 *   not_started ──> [active] ──> completed
 *                       ^
 *                   You are here
 *
 * ALLOWED TRANSITIONS:
 *  - canActivate() = false — Already active, cannot activate again (idempotency guard)
 *  - canComplete() = true  — The cohort can move forward to the completed terminal state
 *
 * WHY CAN'T ACTIVE GO BACK TO NOT_STARTED?
 * Once students are enrolled, reverting to not_started would create an inconsistent
 * state where enrolled students exist in a "not yet started" cohort. The lifecycle
 * is deliberately one-directional to prevent such inconsistencies.
 *
 * BUSINESS RULES IN THIS STATE:
 *  - Students CAN be enrolled and removed
 *  - Capacity limits are enforced during enrolment
 *  - Cohort metadata CAN still be modified (name, dates, capacity, teacher)
 *
 * @see \App\Contracts\CohortState  The interface this implements
 * @see \App\Models\Cohort::state() Where the state object is resolved
 */

namespace App\States;

use App\Contracts\CohortState;

/**
 * A cohort that is currently running.
 *
 * An active cohort can be completed but cannot be re-activated (it already is).
 */
class ActiveState implements CohortState
{
    /**
     * An active cohort CANNOT be activated again — it is already in the active state.
     * Attempting to activate an active cohort returns HTTP 409 Conflict.
     */
    public function canActivate(): bool
    {
        return false;
    }

    /**
     * An active cohort CAN be completed — this is the only valid forward transition.
     * Completing a cohort freezes it and prevents further enrolment changes.
     */
    public function canComplete(): bool
    {
        return true;
    }

    /**
     * The database value for this state: 'active'.
     */
    public function status(): string
    {
        return 'active';
    }
}
