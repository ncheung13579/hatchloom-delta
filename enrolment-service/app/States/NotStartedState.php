<?php

declare(strict_types=1);

namespace App\States;

use App\Contracts\CohortState;

/**
 * Initial state for a newly created cohort.
 *
 * A not_started cohort can be activated but cannot be completed directly.
 */
class NotStartedState implements CohortState
{
    public function canActivate(): bool
    {
        return true;
    }

    public function canComplete(): bool
    {
        return false;
    }

    public function status(): string
    {
        return 'not_started';
    }
}
