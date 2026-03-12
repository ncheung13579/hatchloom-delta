<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a student is enrolled in a cohort.
 *
 * Carries the newly created CohortEnrolment record and the Cohort it belongs
 * to, giving listeners immediate access to all enrolment context (student ID,
 * cohort name, experience, teacher, etc.) without additional queries.
 */
class StudentEnrolled
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CohortEnrolment $enrolment,
        public readonly Cohort $cohort,
    ) {}
}
