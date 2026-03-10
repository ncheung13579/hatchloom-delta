<?php

declare(strict_types=1);

namespace App\States;

use App\Contracts\CohortState;

/**
 * A cohort that is currently running.
 *
 * An active cohort can be completed but cannot be re-activated (it already is).
 */
class ActiveState implements CohortState
{
    public function canActivate(): bool
    {
        return false;
    }

    public function canComplete(): bool
    {
        return true;
    }

    public function status(): string
    {
        return 'active';
    }
}
