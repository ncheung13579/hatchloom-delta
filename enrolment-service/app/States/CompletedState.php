<?php

declare(strict_types=1);

/**
 * CompletedState — The terminal state in the Cohort lifecycle (State pattern).
 *
 * A cohort enters the completed state when an admin explicitly completes it.
 * This represents a cohort that has finished its run. The cohort and its
 * enrolment records are preserved for reporting, CSV export, and statistics,
 * but no further modifications are allowed.
 *
 * LIFECYCLE POSITION:
 *   not_started ──> active ──> [completed]
 *                                    ^
 *                                You are here (terminal — no exit)
 *
 * ALLOWED TRANSITIONS:
 *  - canActivate() = false — Cannot go back to active (completed is final)
 *  - canComplete() = false — Already completed, cannot complete again
 *
 * WHY IS COMPLETED A TERMINAL STATE?
 * Once a cohort is completed, its data represents a historical record of a
 * finished curriculum delivery. Allowing reactivation would:
 *  - Undermine credential evaluations that were triggered at completion
 *  - Confuse reporting (was the cohort active for 4 weeks or 8 weeks?)
 *  - Allow re-enrollment in a cohort that students thought was finished
 *
 * BUSINESS RULES IN THIS STATE:
 *  - Students CANNOT be enrolled or removed
 *  - No state transitions are possible
 *  - Data is read-only (preserved for reporting and export)
 *
 * @see \App\Contracts\CohortState  The interface this implements
 * @see \App\Models\Cohort::state() Where the state object is resolved
 */

namespace App\States;

use App\Contracts\CohortState;

/**
 * Terminal state — the cohort has finished.
 *
 * No transitions are allowed out of the completed state.
 */
class CompletedState implements CohortState
{
    /**
     * A completed cohort CANNOT be reactivated. This is the terminal state.
     */
    public function canActivate(): bool
    {
        return false;
    }

    /**
     * A completed cohort CANNOT be completed again. It is already in the terminal state.
     */
    public function canComplete(): bool
    {
        return false;
    }

    /**
     * The database value for this state: 'completed'.
     */
    public function status(): string
    {
        return 'completed';
    }
}
