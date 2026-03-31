<?php

/**
 * HttpCourseDataProvider — Real implementation calling Team Papa's Course Service.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the production implementation of CourseDataProviderInterface.
 *   It fetches course catalogue data from Papa's Course Service via HTTP.
 *
 * Ideal endpoints (Team Papa's Course Service):
 *   GET /api/courses             — Full course catalogue (paginated or full list)
 *   GET /api/courses/{id}        — Single course by ID
 *   GET /api/courses?ids[]=1&ids[]=2  — Batch lookup by IDs
 *
 * Ideal response shape for a single course:
 *   {
 *     "id": 1,
 *     "name": "Intro to Entrepreneurship",
 *     "description": "Learn the basics of starting and running a business.",
 *     "blocks": [
 *       {"id": 101, "name": "What is a Business?", "status": "complete"},
 *       {"id": 102, "name": "Business Models", "status": "active"}
 *     ]
 *   }
 *
 * Error handling:
 *   All methods degrade gracefully — returning empty arrays or null on failure
 *   rather than throwing exceptions. This lets the Experience Service continue
 *   operating (with reduced functionality) when Papa's service is unavailable.
 *
 * @see \App\Contracts\CourseDataProviderInterface  The interface this implements
 * @see \App\Services\MockCourseDataProvider        The mock fallback
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpCourseDataProvider implements CourseDataProviderInterface
{
    public function getAllCourses(): array
    {
        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            Log::warning('HttpCourseDataProvider: No bearer token available');
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/courses");

            if (!$response->successful()) {
                Log::warning('HttpCourseDataProvider: Failed to fetch courses', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('HttpCourseDataProvider: Error fetching courses', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function getCourse(int $id): ?array
    {
        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/courses/{$id}");

            if ($response->status() === 404) {
                return null;
            }

            if (!$response->successful()) {
                Log::warning('HttpCourseDataProvider: Failed to fetch course', [
                    'course_id' => $id,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('HttpCourseDataProvider: Error fetching course', [
                'course_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function courseExists(int $id): bool
    {
        return $this->getCourse($id) !== null;
    }

    public function getCoursesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $url = config('services.course.url');
        $token = request()->bearerToken();

        if (!$token) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$url}/api/courses", ['ids' => $ids]);

            if (!$response->successful()) {
                Log::warning('HttpCourseDataProvider: Failed to fetch courses by IDs', [
                    'ids' => $ids,
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('HttpCourseDataProvider: Error fetching courses by IDs', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
