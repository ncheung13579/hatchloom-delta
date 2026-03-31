<?php

declare(strict_types=1);

/**
 * CohortController — REST controller for cohort management (CRUD + state transitions).
 *
 * Part of the Enrolment Service (port 8003), which is the leaf service in Team Delta's
 * microservice architecture. This controller owns the HTTP interface for cohort CRUD
 * and the two state-transition endpoints (activate, complete).
 *
 * Design patterns present:
 *  - Controller -> Service -> Model (Repository pattern): This controller is intentionally
 *    thin. It validates input and formats JSON responses, but all business logic (querying,
 *    creation, state transitions) is delegated to CohortService.
 *  - State pattern: The activate() and complete() methods trigger one-directional state
 *    transitions on the Cohort model (not_started -> active -> completed). Invalid
 *    transitions return HTTP 409 Conflict.
 *
 * All cohort queries are automatically scoped to the authenticated user's school_id
 * via the SchoolScope global scope on the Cohort model, so this controller never needs
 * to manually filter by school.
 *
 * @see \App\Services\CohortService  Business logic layer
 * @see \App\Models\Cohort           Eloquent model with State pattern integration
 */

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\RequiresTeacherAdmin;
use App\Models\Cohort;
use App\Models\Experience;
use App\Services\CohortService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * REST controller for cohort management (CRUD + state transitions).
 *
 * Handles listing, creating, updating, and retrieving cohorts, as well as
 * the activate and complete actions that drive the cohort state lifecycle.
 * All business logic is delegated to CohortService.
 */
class CohortController extends Controller
{
    use RequiresTeacherAdmin;

    /**
     * Constructor injection of the CohortService.
     *
     * Laravel's service container automatically resolves CohortService since it has
     * no interface binding — it is a concrete class. The "readonly" modifier ensures
     * the dependency cannot be reassigned after construction.
     */
    public function __construct(
        private readonly CohortService $cohortService
    ) {}

    /**
     * List all cohorts for the authenticated user's school, with optional filters.
     *
     * Supports three query parameters for narrowing results:
     *  - experience_id: show only cohorts belonging to a specific experience
     *  - status: show only cohorts in a specific lifecycle state (not_started, active, completed)
     *  - search: case-insensitive substring match on the cohort name
     *
     * The response includes computed fields (student_count, removed_count) that are
     * derived from the cohort_enrolments table, giving admins a quick headcount
     * without needing a separate API call.
     */
    public function index(Request $request): JsonResponse
    {
        // Extract optional query parameters; experience_id is cast to int if present
        $experienceId = $request->query('experience_id') ? (int) $request->query('experience_id') : null;
        $status = $request->query('status');
        $search = $request->query('search');

        // CohortService handles the filtered query — SchoolScope ensures tenant isolation
        $cohorts = $this->cohortService->listCohorts($experienceId, $status, $search);

        $data = $cohorts->map(fn($cohort) => $cohort->toApiArray());

        return response()->json(['data' => $data]);
    }

    /**
     * Create a new cohort under an existing experience.
     *
     * Validation rules enforce:
     *  - experience_id must reference an existing experience (foreign key check)
     *  - start_date must be today or later (cannot create retroactive cohorts)
     *  - end_date must be after start_date (ensures a valid date range)
     *  - capacity is optional but must be at least 1 if provided
     *  - teacher_id is optional but must reference an existing user if provided
     *
     * The cohort is always created with status='not_started' — the State pattern
     * requires it to be explicitly activated via the activate endpoint.
     *
     * Returns HTTP 201 Created on success.
     */
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('create cohorts')) {
            return $denied;
        }

        $validated = $request->validate([
            'experience_id' => 'required|integer|exists:experiences,id',
            'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after:start_date',
            'capacity' => 'nullable|integer|min:1|max:10000',
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);

        // CohortService assigns the authenticated user's school_id automatically
        $cohort = $this->cohortService->createCohort($validated);

        return response()->json([
            'id' => $cohort->id,
            'name' => $cohort->name,
            'experience_id' => $cohort->experience_id,
            'status' => $cohort->status,
            'capacity' => $cohort->capacity,
            'start_date' => $cohort->start_date?->format('Y-m-d'),
            'end_date' => $cohort->end_date?->format('Y-m-d'),
            'created_at' => $cohort->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Retrieve a single cohort by ID.
     *
     * SchoolScope ensures the cohort must belong to the authenticated user's school;
     * if the ID exists but belongs to another school, Eloquent returns null and we
     * respond with 404 — the caller never learns the cohort exists in another tenant.
     */
    public function show(int $id): JsonResponse
    {
        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return $this->notFoundResponse('Cohort not found');
        }

        return response()->json($cohort->toApiArray());
    }

    /**
     * Update mutable fields on an existing cohort.
     *
     * Uses 'sometimes' validation so the client can send a partial payload —
     * only the fields included in the request body are updated. Note that
     * experience_id and status are NOT updatable through this endpoint;
     * status changes must go through activate() or complete().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('update cohorts')) {
            return $denied;
        }

        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return $this->notFoundResponse('Cohort not found');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/\S/'],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'capacity' => 'sometimes|integer|min:1|max:10000',
            'teacher_id' => 'sometimes|integer|exists:users,id',
        ]);

        $cohort = $this->cohortService->updateCohort($cohort, $validated);

        return response()->json([
            'id' => $cohort->id,
            'name' => $cohort->name,
            'experience_id' => $cohort->experience_id,
            'status' => $cohort->status,
            'capacity' => $cohort->capacity,
            'start_date' => $cohort->start_date?->format('Y-m-d'),
            'end_date' => $cohort->end_date?->format('Y-m-d'),
        ]);
    }

    /**
     * Transition a cohort to active status.
     *
     * Enforces the state lifecycle: only not_started cohorts can be activated.
     * Returns 409 Conflict if the transition is invalid. This is the first step
     * in the one-directional lifecycle: not_started -> active -> completed.
     *
     * Once active, the cohort accepts student enrolments. Before activation,
     * students cannot be enrolled.
     */
    public function activate(int $id): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('activate cohorts')) {
            return $denied;
        }

        return DB::transaction(function () use ($id) {
            $cohort = Cohort::lockForUpdate()->find($id);

            if (!$cohort) {
                return $this->notFoundResponse('Cohort not found');
            }

            if (!$cohort->activate()) {
                return $this->errorResponse('Cohort is already active or completed', 'INVALID_STATE_TRANSITION', 409);
            }

            return response()->json([
                'id' => $cohort->id,
                'name' => $cohort->name,
                'status' => $cohort->status,
            ]);
        });
    }

    /**
     * Transition a cohort to completed status (terminal state).
     *
     * Enforces the state lifecycle: only active cohorts can be completed.
     * Returns 409 Conflict if the transition is invalid. Completed is the
     * terminal state — once reached, the cohort cannot be reactivated or
     * modified further. This is intentional: completed cohorts represent
     * finished curriculum delivery and their data is preserved for reporting.
     */
    public function complete(int $id): JsonResponse
    {
        if ($denied = $this->authorizeTeacherAdmin('complete cohorts')) {
            return $denied;
        }

        return DB::transaction(function () use ($id) {
            $cohort = Cohort::lockForUpdate()->find($id);

            if (!$cohort) {
                return $this->notFoundResponse('Cohort not found');
            }

            if (!$cohort->complete()) {
                return $this->errorResponse('Cohort must be active to complete', 'INVALID_STATE_TRANSITION', 409);
            }

            return response()->json([
                'id' => $cohort->id,
                'name' => $cohort->name,
                'status' => $cohort->status,
            ]);
        });
    }

}
