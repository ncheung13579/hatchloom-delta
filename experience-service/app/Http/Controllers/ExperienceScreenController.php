<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ExperienceScreenService;
use App\Services\ExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExperienceScreenController extends Controller
{
    public function __construct(
        private readonly ExperienceService $experienceService,
        private readonly ExperienceScreenService $screenService
    ) {}

    public function students(Request $request, int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);
        $result = $this->screenService->getEnrolledStudents($experience, $search, $perPage);

        return response()->json($result);
    }

    public function contents(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($this->screenService->getContentsAndDelivery($experience));
    }

    public function statistics(int $id): JsonResponse
    {
        $experience = $this->experienceService->getExperience($id);

        if (!$experience) {
            return response()->json([
                'error' => true,
                'message' => 'Experience not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($this->screenService->getExperienceStatistics($experience));
    }
}
