<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->getDashboardOverview());
    }

    public function studentDrillDown(int $studentId): JsonResponse
    {
        $result = $this->dashboardService->getStudentDrillDown($studentId);

        if (!$result) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json($result);
    }
}
