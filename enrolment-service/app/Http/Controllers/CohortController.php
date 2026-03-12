<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Experience;
use App\Services\CohortService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST controller for cohort management (CRUD + state transitions).
 *
 * Handles listing, creating, updating, and retrieving cohorts, as well as
 * the activate and complete actions that drive the cohort state lifecycle.
 * All business logic is delegated to CohortService.
 */
class CohortController extends Controller
{
    public function __construct(
        private readonly CohortService $cohortService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $experienceId = $request->query('experience_id') ? (int) $request->query('experience_id') : null;
        $status = $request->query('status');
        $search = $request->query('search');

        $cohorts = $this->cohortService->listCohorts($experienceId, $status, $search);

        $data = $cohorts->map(function ($cohort) {
            return [
                'id' => $cohort->id,
                'name' => $cohort->name,
                'experience_id' => $cohort->experience_id,
                'status' => $cohort->status,
                'teacher_name' => $cohort->teacher?->name,
                'student_count' => $cohort->activeEnrolments()->count(),
                'removed_count' => $cohort->removedCount(),
                'capacity' => $cohort->capacity,
                'start_date' => $cohort->start_date?->format('Y-m-d'),
                'end_date' => $cohort->end_date?->format('Y-m-d'),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'experience_id' => 'required|integer|exists:experiences,id',
            'name' => 'required|string|max:255',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after:start_date',
            'capacity' => 'nullable|integer|min:1',
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);

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

    public function show(int $id): JsonResponse
    {
        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'id' => $cohort->id,
            'name' => $cohort->name,
            'experience_id' => $cohort->experience_id,
            'status' => $cohort->status,
            'teacher_name' => $cohort->teacher?->name,
            'student_count' => $cohort->activeEnrolments()->count(),
            'removed_count' => $cohort->removedCount(),
            'capacity' => $cohort->capacity,
            'start_date' => $cohort->start_date?->format('Y-m-d'),
            'end_date' => $cohort->end_date?->format('Y-m-d'),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'capacity' => 'sometimes|integer|min:1',
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
     * Returns 409 Conflict if the transition is invalid.
     */
    public function activate(int $id): JsonResponse
    {
        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (!$this->cohortService->activateCohort($cohort)) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort is already active or completed',
                'code' => 'INVALID_STATE_TRANSITION',
            ], 409);
        }

        return response()->json([
            'id' => $cohort->id,
            'name' => $cohort->name,
            'status' => $cohort->status,
        ]);
    }

    /**
     * Transition a cohort to completed status (terminal state).
     *
     * Enforces the state lifecycle: only active cohorts can be completed.
     * Returns 409 Conflict if the transition is invalid.
     */
    public function complete(int $id): JsonResponse
    {
        $cohort = $this->cohortService->getCohort($id);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (!$this->cohortService->completeCohort($cohort)) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort must be active to complete',
                'code' => 'INVALID_STATE_TRANSITION',
            ], 409);
        }

        return response()->json([
            'id' => $cohort->id,
            'name' => $cohort->name,
            'status' => $cohort->status,
        ]);
    }
}
