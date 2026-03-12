<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a student is removed from a cohort.
 *
 * Carries the updated CohortEnrolment (now with status=removed), the Cohort,
 * and the exact removal timestamp so listeners can log or react to the removal
 * with full context.
 */
class StudentRemoved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CohortEnrolment $enrolment,
        public readonly Cohort $cohort,
        public readonly \DateTimeInterface $removedAt,
    ) {}
}
