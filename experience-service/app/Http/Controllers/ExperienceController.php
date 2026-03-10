<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ExperienceService;
use App\Services\MockCourseDataProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Thin REST controller for Experience CRUD operations (Screen 301).
 *
 * Handles input validation and response formatting only — all business
 * logic is delegated to ExperienceService. Course names are resolved via
 * MockCourseDataProvider, and cohort data is fetched from the Enrolment
 * Service over HTTP.
 */
class ExperienceController extends Controller
{
    public function __construct(
        private readonly ExperienceService $experienceService,
        private readonly MockCourseDataProvider $courseDataProvider
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $search = $request->query('search');

        $experiences = $this->experienceService->listExperiences($perPage, $search);

        $data = $experiences->map(function ($experience) {
            return [
                'id' => $experience->id,
                'name' => $experience->name,
                'description' => $experience->description,
                'status' => $experience->status,
                'course_count' => $experience->courses->count(),
                'cohort_count' => 0, // Would come from enrolment service
                'created_by' => $experience->creator?->name,
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
     * Create a new Experience after validating that all course_ids exist
     * in the upstream course catalogue (currently mocked).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'required|integer',
        ]);

        if (!$this->experienceService->validateCourseIds($validated['course_ids'])) {
            return response()->json([
                'error' => true,
                'message' => 'One or more course IDs are invalid',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $experience = $this->experienceService->createExperience($validated);

        $courses = $experience->courses->map(fn($c) => [
            'id' => $c->course_id,
            'name' => $this->courseDataProvider->getCourse($c->course_id)['name'] ?? 'Unknown',
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
     * Show a single Experience with its courses and cohorts.
     *
     * Cohort data is fetched from the Enrolment Service via HTTP. If the
     * Enrolment Service is unreachable, the response degrades gracefully
     * with an empty cohorts array.
     */
    public function show(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $courses = $experience->courses->map(fn($c) => [
            'id' => $c->course_id,
            'name' => $this->courseDataProvider->getCourse($c->course_id)['name'] ?? 'Unknown',
            'sequence' => $c->sequence,
        ]);

        // Fetch cohort data from the Enrolment Service
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
            // Degraded — return empty cohorts on failure
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

    public function update(Request $request, int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'required|integer',
        ]);

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

    public function destroy(int $id): JsonResponse
    {
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
