<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cohort lifecycle states.
 *
 * Replaces scattered string literals ('not_started', 'active', 'completed')
 * with a type-safe enum. The State pattern transitions follow the order:
 * NOT_STARTED -> ACTIVE -> COMPLETED (one-directional).
 */
enum CohortStatus: string
{
    case NOT_STARTED = 'not_started';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
}
