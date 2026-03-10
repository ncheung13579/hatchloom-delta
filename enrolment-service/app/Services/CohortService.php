<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Manages the full cohort lifecycle: CRUD operations and state transitions.
 *
 * Cohorts follow a one-directional state machine: not_started -> active -> completed.
 * State transition logic is enforced by the Cohort model; this service acts as the
 * boundary between controllers and the model layer.
 */
class CohortService
{
    public function listCohorts(?int $experienceId = null, ?string $status = null): Collection
    {
        $query = Cohort::query()->with(['experience', 'teacher']);

        if ($experienceId) {
            $query->where('experience_id', $experienceId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    public function getCohort(int $id): ?Cohort
    {
        return Cohort::with(['experience', 'teacher', 'enrolments'])->find($id);
    }

    public function createCohort(array $data): Cohort
    {
        return Cohort::create([
            'experience_id' => $data['experience_id'],
            'school_id' => Auth::user()->school_id,
            'name' => $data['name'],
            'status' => 'not_started',
            'teacher_id' => $data['teacher_id'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
        ]);
    }

    public function updateCohort(Cohort $cohort, array $data): Cohort
    {
        $cohort->update(array_filter([
            'name' => $data['name'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
        ], fn($v) => $v !== null));

        return $cohort->fresh();
    }

    /**
     * Transition a cohort from not_started to active.
     *
     * Only not_started cohorts can be activated. Returns false if the transition
     * is invalid (cohort is already active or completed). The state machine is
     * one-directional — there is no way to revert to a previous state.
     */
    public function activateCohort(Cohort $cohort): bool
    {
        return $cohort->activate();
    }

    /**
     * Transition a cohort from active to completed.
     *
     * Only active cohorts can be completed. Returns false if the cohort is still
     * in not_started or already completed. This is a terminal state — once
     * completed, a cohort cannot be reactivated.
     */
    public function completeCohort(Cohort $cohort): bool
    {
        return $cohort->complete();
    }
}
