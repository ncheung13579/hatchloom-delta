<?php

/**
 * ExperienceController — REST controller for Experience CRUD (Screen 301: Experiences Dashboard).
 *
 * Architecture role:
 *   This is the entry point for all Experience list/create/show/update/delete HTTP requests.
 *   It follows the Controller -> Service -> Model pattern mandated by CLAUDE.md: the controller
 *   is kept thin (validates input, formats JSON responses), while ExperienceService holds the
 *   business logic.
 *
 * Cross-service communication:
 *   The Experience Service does NOT own cohort or student data — that lives in the Enrolment
 *   Service (port 8003). Several methods here make HTTP calls to the Enrolment Service to
 *   fetch cohort counts and details. All such calls are wrapped in try/catch and degrade
 *   gracefully (returning zeros or empty arrays) so the Experience Service stays functional
 *   even when the Enrolment Service is down.
 *
 * Design patterns:
 *   - Strategy pattern: CourseDataProviderInterface is injected via constructor DI. When using
 *     mock providers, the service container resolves this to MockCourseDataProvider. To switch
 *     to a real HTTP-backed provider, only the binding in AppServiceProvider needs to change.
 *   - Repository pattern: ExperienceService acts as the repository boundary, keeping Eloquent
 *     queries out of the controller.
 *
 * @see \App\Services\ExperienceService           Business logic layer
 * @see \App\Contracts\CourseDataProviderInterface Strategy interface for course data
 * @see \App\Http\Controllers\ExperienceScreenController  Companion controller for Screen 302
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\CourseDataProviderInterface;
use App\Services\ExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExperienceController extends Controller
{
    /**
     * Dependencies are injected by Laravel's service container.
     *
     * @param ExperienceService            $experienceService  Handles all Experience business logic (CRUD, validation).
     * @param CourseDataProviderInterface   $courseDataProvider  Resolves course IDs to names/details. Currently mocked;
     *                                                          will be swapped to a real HTTP provider when Team Papa's
     *                                                          Course Service is ready.
     */
    public function __construct(
        private readonly ExperienceService $experienceService,
        private readonly CourseDataProviderInterface $courseDataProvider
    ) {}

    /**
     * GET /api/school/experiences — List all Experiences for the authenticated school.
     *
     * Supports optional query parameters:
     *   - per_page (int, default 15): pagination page size
     *   - search (string, optional): case-insensitive partial match on experience name
     *
     * Results are automatically filtered to the current user's school by the SchoolScope
     * global scope on the Experience model — no explicit WHERE clause needed here.
     *
     * Cross-service call: fetches ALL cohorts from the Enrolment Service so we can
     * compute cohort_count per experience. This is a single batch call (not N+1) to
     * keep latency low.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $search = $request->query('search');

        // Service handles the paginated query; SchoolScope ensures tenant isolation automatically.
        $experiences = $this->experienceService->listExperiences($perPage, $search);

        // --- Cross-service HTTP call to the Enrolment Service (port 8003) ---
        // Endpoint: GET /api/school/cohorts
        // Purpose:  Retrieve all cohorts for this school, then group by experience_id
        //           so we can display an accurate cohort_count on each experience card.
        // On failure: cohortCounts stays empty; each experience shows cohort_count = 0.
        //             This is acceptable degradation — the experience list is still usable.
        $cohortCounts = collect();
        try {
            $response = Http::withToken($request->bearerToken())
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts');

            if ($response->successful()) {
                // Group the flat cohort list by experience_id and count each group.
                $cohortCounts = collect($response->json('data', []))
                    ->groupBy('experience_id')
                    ->map(fn($group) => $group->count());
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch cohort counts from Enrolment Service', ['error' => $e->getMessage()]);
        }

        // Build the response payload — one flat object per experience.
        $data = $experiences->map(function ($experience) use ($cohortCounts) {
            return [
                'id' => $experience->id,
                'name' => $experience->name,
                'description' => $experience->description,
                'status' => $experience->status,
                'course_count' => $experience->courses->count(),          // Local data — eager-loaded via ->with('courses')
                'cohort_count' => $cohortCounts->get($experience->id, 0), // Remote data — from Enrolment Service
                'created_by' => $experience->creator?->name,              // Null-safe: creator may have been deleted
                'created_at' => $experience->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $experiences->currentPage(),
                'last_page' => $experiences->lastPage(),
                'per_page' => $experiences->perPage(),
                'total' => $experiences->total(),
            ],
        ]);
    }

    /**
     * POST /api/school/experiences — Create a new Experience.
     *
     * Required fields: name, description, course_ids (array of upstream course IDs).
     * The course_ids are validated against the CourseDataProviderInterface to ensure
     * they reference real courses in the catalogue (currently mocked). This two-step
     * validation (Laravel rules first, then business-logic check) prevents orphaned
     * references to non-existent courses.
     *
     * The newly created Experience is automatically assigned to the authenticated
     * user's school_id and marked as 'active'. Course order in the request array
     * determines the sequence numbering (1-based).
     */
    public function store(Request $request): JsonResponse
    {
        // Per Roles PDF: only School Teachers build experiences.
        // School Admins can only manage enrolments (add/remove students from cohorts).
        if (Auth::user()->role !== 'school_teacher') {
            return response()->json([
                'error' => true,
                'message' => 'Only school teachers can create experiences',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        // Step 1: Structural validation — Laravel handles type/format checks.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'description' => 'required|string|max:5000',
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'required|integer',       // Each element must be an integer
        ]);

        // Step 2: Business validation — check that every course_id exists in the catalogue.
        // This uses the Strategy-pattern provider, so the check works identically whether
        // we're using mock data or a real HTTP call to Team Papa's service.
        if (!$this->experienceService->validateCourseIds($validated['course_ids'])) {
            return response()->json([
                'error' => true,
                'message' => 'One or more course IDs are invalid',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $experience = $this->experienceService->createExperience($validated);

        // Batch-resolve course names from the provider in a single call.
        $courseIds = $experience->courses->pluck('course_id')->all();
        $courseMap = collect($this->courseDataProvider->getCoursesByIds($courseIds))->keyBy('id');
        $courses = $experience->courses->map(fn($c) => [
            'id' => $c->course_id,
            'name' => $courseMap->get($c->course_id)['name'] ?? 'Unknown',
            'sequence' => $c->sequence,
        ]);

        return response()->json([
            'id' => $experience->id,
            'name' => $experience->name,
            'description' => $experience->description,
            'status' => $experience->status,
            'courses' => $courses,
            'created_at' => $experience->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/school/experiences/{id} — Show a single Experience with courses and cohorts.
     *
     * Combines data from three sources:
     *   1. Local DB: Experience metadata (name, description, status, creator)
     *   2. CourseDataProvider (Strategy pattern): Course names resolved from IDs
     *   3. Enrolment Service (HTTP): Cohort list with student counts
     *
     * Cross-service call to Enrolment Service:
     *   Endpoint: GET /api/school/cohorts?experience_id={id}
     *   Expected response: { data: [{ id, name, status, student_count }, ...] }
     *   On failure: Returns empty cohorts array — the experience detail page is
     *               still usable, just missing cohort information.
     *
     * Note: The SchoolScope on the Experience model ensures this can only return
     * experiences belonging to the authenticated user's school.
     */
    public function show(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        // SchoolScope already filters by school_id, so a null result means either
        // the experience doesn't exist or it belongs to a different school.
        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        // Batch-resolve course names from the provider in a single call.
        $courseIds = $experience->courses->pluck('course_id')->all();
        $courseMap = collect($this->courseDataProvider->getCoursesByIds($courseIds))->keyBy('id');
        $courses = $experience->courses->map(fn($c) => [
            'id' => $c->course_id,
            'name' => $courseMap->get($c->course_id)['name'] ?? 'Unknown',
            'sequence' => $c->sequence,
        ]);

        // --- Cross-service HTTP call to the Enrolment Service (port 8003) ---
        // Endpoint: GET /api/school/cohorts?experience_id={id}
        // Purpose:  Retrieve cohorts that belong to this specific experience, with
        //           student_count per cohort so the UI can render cohort cards.
        // On failure: cohorts stays empty — the rest of the response is still valid.
        $cohorts = [];
        try {
            $token = request()->bearerToken();
            $cohortResponse = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/cohorts', [
                    'experience_id' => $experience->id,
                ]);
            if ($cohortResponse->successful()) {
                $cohorts = collect($cohortResponse->json('data', []))->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'status' => $c['status'],
                    'student_count' => $c['student_count'],
                ])->all();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch cohorts from Enrolment Service', ['experience_id' => $experience->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $experience->id,
            'name' => $experience->name,
            'description' => $experience->description,
            'status' => $experience->status,
            'courses' => $courses,
            'cohorts' => $cohorts,
            'created_by' => $experience->creator?->name,
            'created_at' => $experience->created_at?->toIso8601String(),
        ]);
    }

    /**
     * PUT/PATCH /api/school/experiences/{id} — Update an existing Experience.
     *
     * All fields are optional ('sometimes' rule) so clients can send partial updates.
     * If course_ids is included, the entire course list is replaced (not merged) to
     * keep sequence numbering deterministic. Course IDs are re-validated against the
     * provider before replacement.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (Auth::user()->role !== 'school_teacher') {
            return response()->json([
                'error' => true,
                'message' => 'Only school teachers can update experiences',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        // 'sometimes' means the field is only validated if present in the request,
        // allowing partial updates without requiring every field to be sent.
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/\S/'],
            'description' => 'sometimes|string|max:5000',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'required|integer',
        ]);

        // Only validate course_ids if the client is actually changing the course list.
        if (isset($validated['course_ids']) && !$this->experienceService->validateCourseIds($validated['course_ids'])) {
            return response()->json([
                'error' => true,
                'message' => 'One or more course IDs are invalid',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $experience = $this->experienceService->updateExperience($experience, $validated);

        return response()->json([
            'id' => $experience->id,
            'name' => $experience->name,
            'description' => $experience->description,
            'status' => $experience->status,
            'created_at' => $experience->created_at?->toIso8601String(),
        ]);
    }

    /**
     * DELETE /api/school/experiences/{id} — Soft-delete (archive) an Experience.
     *
     * This does NOT hard-delete the record. The service layer sets status to 'archived'
     * and then applies a Laravel soft delete (sets deleted_at timestamp). The record
     * remains in the database for audit and reporting purposes but is excluded from
     * all normal queries by Eloquent's SoftDeletes trait.
     */
    public function destroy(int $id): JsonResponse
    {
        if (Auth::user()->role !== 'school_teacher') {
            return response()->json([
                'error' => true,
                'message' => 'Only school teachers can delete experiences',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $this->experienceService->deleteExperience($experience);

        return response()->json(['message' => 'Experience archived']);
    }
}
