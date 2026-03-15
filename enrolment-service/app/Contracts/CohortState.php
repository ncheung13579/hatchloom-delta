<?php

declare(strict_types=1);

/**
 * CohortState — State pattern interface for the Cohort lifecycle (SDD Section 6.3).
 *
 * Defines the contract that each cohort state must implement. The Cohort model
 * resolves its current state from the status column and delegates transition
 * checks to the corresponding CohortState object.
 *
 * THE LIFECYCLE (one-directional, no going back):
 *
 *   not_started ──> active ──> completed
 *       |              |            |
 *   canActivate:    canActivate:  canActivate:
 *     true           false         false
 *   canComplete:    canComplete:  canComplete:
 *     false          true          false
 *
 * WHY ONE-DIRECTIONAL:
 *  - not_started: Cohort is still being configured. No students can enrol.
 *    Going backward from active would leave enrolled students in an inconsistent state.
 *  - active: Cohort is running. Students can be enrolled and removed.
 *    Going backward from completed would re-open a finished cohort, undermining
 *    the integrity of completion records and credential evaluations.
 *  - completed: Terminal state. The cohort is frozen for reporting. No changes allowed.
 *
 * HOW THE PATTERN WORKS:
 *  1. Cohort::state() reads the status column and instantiates the matching state object
 *  2. Cohort::activate() calls $this->state()->canActivate() before transitioning
 *  3. Cohort::complete() calls $this->state()->canComplete() before transitioning
 *  4. If the current state returns false, the transition is rejected (HTTP 409)
 *
 * Concrete implementations:
 * @see \App\States\NotStartedState  Initial state — can activate only
 * @see \App\States\ActiveState      Running state — can complete only
 * @see \App\States\CompletedState   Terminal state — no transitions allowed
 * @see \App\Models\Cohort           Model that uses this interface
 */

namespace App\Contracts;

/**
 * State pattern interface for the Cohort lifecycle.
 *
 * Each concrete state defines which transitions are valid from that state.
 * The lifecycle is one-directional: not_started -> active -> completed.
 */
interface CohortState
{
    /**
     * Whether the cohort can transition to "active" from this state.
     *
     * Only NotStartedState returns true. ActiveState and CompletedState return false
     * because a cohort cannot be activated if it is already active or has finished.
     */
    public function canActivate(): bool;

    /**
     * Whether the cohort can transition to "completed" from this state.
     *
     * Only ActiveState returns true. NotStartedState cannot skip directly to completed
     * (it must be activated first), and CompletedState cannot be completed again.
     */
    public function canComplete(): bool;

    /**
     * The string identifier stored in the database for this state.
     *
     * Returns the value that gets written to the cohorts.status column:
     * 'not_started', 'active', or 'completed'.
     */
    public function status(): string;
}
