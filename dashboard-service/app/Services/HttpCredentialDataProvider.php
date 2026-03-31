<?php

/**
 * HttpCredentialDataProvider — Real HTTP implementation calling Karl's Credential Engine.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the HTTP implementation of CredentialDataProviderInterface.
 *   It fetches credential and curriculum mapping data from Karl's Credential
 *   Engine REST API.
 *
 * Ideal endpoints on Karl's side:
 *   GET /api/credentials/students/{studentId}
 *     → returns [{id, type, name, issuing_course, earned_at, status}]
 *   GET /api/credentials/students/{studentId}/curriculum-mapping
 *     → returns {area_key: {area_name, requirements_met[], total_requirements, coverage_percentage}}
 *
 * Authentication:
 *   Forwards the current request's bearer token to Karl's API.
 *
 * Graceful degradation:
 *   On HTTP failure or timeout, returns empty arrays (same shape as mock)
 *   so the dashboard renders without credential data rather than crashing.
 *
 * @see \App\Contracts\CredentialDataProviderInterface  The interface this implements
 * @see \App\Services\MockCredentialDataProvider        The mock fallback
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CredentialDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpCredentialDataProvider implements CredentialDataProviderInterface
{
    /**
     * Fetch credentials earned by a student from Karl's Credential Engine.
     *
     * Ideal endpoint: GET /api/credentials/students/{studentId}
     * Ideal response shape:
     *   [
     *     { "id": 1, "type": "credential", "name": "...", "issuing_course": "...",
     *       "earned_at": "2026-02-15T00:00:00Z", "status": "earned" }
     *   ]
     *
     * If Karl's response uses different field names, the mapping logic in
     * this method should be updated to normalize the response.
     */
    public function getStudentCredentials(int $studentId): array
    {
        $credentialServiceUrl = config('services.credential.url');
        $token = request()->bearerToken();

        if (!$token) {
            Log::warning('HttpCredentialDataProvider: No bearer token available');
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$credentialServiceUrl}/api/credentials/students/{$studentId}");

            if (!$response->successful()) {
                Log::warning('HttpCredentialDataProvider: Failed to fetch student credentials', [
                    'student_id' => $studentId,
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('HttpCredentialDataProvider: Error fetching student credentials', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch Alberta PoS curriculum mapping for a student from Karl's Credential Engine.
     *
     * Ideal endpoint: GET /api/credentials/students/{studentId}/curriculum-mapping
     * Ideal response shape:
     *   {
     *     "business_studies": {
     *       "area_name": "Business Studies",
     *       "requirements_met": [{ "code": "BS-1.1", "description": "...", "met_by": "..." }],
     *       "total_requirements": 8,
     *       "coverage_percentage": 0.38
     *     },
     *     ...
     *   }
     */
    public function getStudentCurriculumMapping(int $studentId): array
    {
        $credentialServiceUrl = config('services.credential.url');
        $token = request()->bearerToken();

        if (!$token) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$credentialServiceUrl}/api/credentials/students/{$studentId}/curriculum-mapping");

            if (!$response->successful()) {
                Log::warning('HttpCredentialDataProvider: Failed to fetch curriculum mapping', [
                    'student_id' => $studentId,
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('HttpCredentialDataProvider: Error fetching curriculum mapping', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
