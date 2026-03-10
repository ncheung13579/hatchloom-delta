<?php

declare(strict_types=1);

namespace App\States;

use App\Contracts\CohortState;

/**
 * Terminal state — the cohort has finished.
 *
 * No transitions are allowed out of the completed state.
 */
class CompletedState implements CohortState
{
    public function canActivate(): bool
    {
        return false;
    }

    public function canComplete(): bool
    {
        return false;
    }

    public function status(): string
    {
        return 'completed';
    }
}
