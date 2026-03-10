<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * State pattern interface for the Cohort lifecycle.
 *
 * Each concrete state defines which transitions are valid from that state.
 * The lifecycle is one-directional: not_started → active → completed.
 */
interface CohortState
{
    /**
     * Whether the cohort can transition to "active" from this state.
     */
    public function canActivate(): bool;

    /**
     * Whether the cohort can transition to "completed" from this state.
     */
    public function canComplete(): bool;

    /**
     * The string identifier stored in the database for this state.
     */
    public function status(): string;
}
