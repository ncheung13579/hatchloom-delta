<?php

declare(strict_types=1);

/**
 * CohortService — Business logic layer for cohort CRUD and state transitions.
 *
 * Part of the Controller -> Service -> Model (Repository pattern) architecture.
 * This service acts as the boundary between CohortController and the Cohort model,
 * encapsulating query logic, creation defaults, and state transition delegation.
 *
 * The CohortController never calls Eloquent methods directly on the Cohort model
 * for business operations — it always goes through this service. This separation
 * makes the code easier to test (you can mock this service) and keeps controllers
 * thin.
 *
 * State transitions (activate, complete) are delegated to the Cohort model itself,
 * which uses the State pattern internally. This service simply calls the model
 * methods and returns the boolean result.
 *
 * All queries on the Cohort model are automatically scoped to the authenticated
 * user's school via SchoolScope, so this service does not need to add school_id
 * filtering manually (except during creation, where it sets school_id explicitly).
 *
 * @see \App\Http\Controllers\CohortController  The controller that uses this service
 * @see \App\Models\Cohort                       The model with State pattern integration
 * @see \App\Models\Scopes\SchoolScope           Automatic tenant filtering
 */

namespace App\Services;

use App\Enums\CohortStatus;
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
    /**
     * List cohorts with optional filtering by experience, status, and name search.
     *
     * Eager-loads the experience and teacher relationships to avoid N+1 queries
     * when the controller maps cohorts to their JSON representation.
     *
     * The LOWER() + LIKE pattern for search ensures case-insensitive matching
     * on PostgreSQL without requiring a special index.
     *
     * @param int|null    $experienceId Filter to cohorts of a specific experience
     * @param string|null $status       Filter to a specific lifecycle state
     * @param string|null $search       Case-insensitive substring match on name
     * @return Collection<Cohort>
     */
    public function listCohorts(?int $experienceId = null, ?string $status = null, ?string $search = null): Collection
    {
        $query = Cohort::query()
            ->with(['experience', 'teacher'])
            ->withCount(['activeEnrolments', 'removedEnrolments']);

        if ($experienceId) {
            $query->where('experience_id', $experienceId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%']);
        }

        return $query->get();
    }

    /**
     * Retrieve a single cohort by ID with all its relationships eager-loaded.
     *
     * Returns null if the cohort does not exist or belongs to a different school
     * (SchoolScope filters it out transparently).
     */
    public function getCohort(int $id): ?Cohort
    {
        return Cohort::with(['experience', 'teacher', 'enrolments'])
            ->withCount(['activeEnrolments', 'removedEnrolments'])
            ->find($id);
    }

    /**
     * Create a new cohort under an existing experience.
     *
     * Sets school_id from the authenticated user (not from the request body)
     * to prevent a client from creating cohorts in another school's namespace.
     * The initial status is always 'not_started' — the State pattern requires
     * explicit activation via the activate endpoint.
     */
    public function createCohort(array $data): Cohort
    {
        return Cohort::create([
            'experience_id' => $data['experience_id'],
            'school_id' => Auth::user()->school_id, // Always use the authenticated user's school
            'name' => $data['name'],
            'status' => CohortStatus::NOT_STARTED->value,
            'teacher_id' => $data['teacher_id'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
        ]);
    }

    /**
     * Update mutable fields on an existing cohort.
     *
     * Uses array_filter to strip null values so only fields explicitly provided
     * in the request are updated. This supports partial updates — the client
     * can send just { "name": "New Name" } without affecting other fields.
     *
     * Returns a fresh copy of the model to ensure the response reflects the
     * latest database state (including any default values or triggers).
     */
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
     *
     * Delegates to Cohort::activate() which uses the State pattern internally.
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
     *
     * Delegates to Cohort::complete() which uses the State pattern internally.
     */
    public function completeCohort(Cohort $cohort): bool
    {
        return $cohort->complete();
    }
}
