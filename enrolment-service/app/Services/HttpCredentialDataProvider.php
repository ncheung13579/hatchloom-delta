<?php

/**
 * HttpCredentialDataProvider — Real HTTP implementation calling Karl's Credential Engine.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the HTTP implementation of CredentialDataProviderInterface
 *   for the Enrolment Service. It fetches a credential summary for a student
 *   from Karl's Credential Engine REST API.
 *
 * Ideal endpoint on Karl's side:
 *   GET /api/credentials/students/{studentId}/summary
 *     → returns { total_earned: int, in_progress: int, details: [...] }
 *
 * Authentication:
 *   Forwards the current request's bearer token to Karl's API.
 *
 * Graceful degradation:
 *   On HTTP failure or timeout, returns zeroed-out summary (same shape as mock)
 *   so the student detail view renders without credential data rather than crashing.
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
    private function defaultSummary(): array
    {
        return [
            'total_earned' => 0,
            'in_progress' => 0,
            'details' => [],
        ];
    }

    /**
     * Fetch a credential summary for a student from Karl's Credential Engine.
     *
     * Ideal endpoint: GET /api/credentials/students/{studentId}/summary
     * Ideal response shape:
     *   {
     *     "total_earned": 2,
     *     "in_progress": 1,
     *     "details": [
     *       { "id": 1, "name": "...", "type": "credential", "status": "earned", "earned_at": "2026-02-15" }
     *     ]
     *   }
     */
    public function getStudentCredentialSummary(int $studentId): array
    {
        $credentialServiceUrl = config('services.credential.url');
        $token = request()->bearerToken();

        if (!$token) {
            Log::warning('HttpCredentialDataProvider: No bearer token available');
            return $this->defaultSummary();
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$credentialServiceUrl}/api/credentials/students/{$studentId}/summary");

            if (!$response->successful()) {
                Log::warning('HttpCredentialDataProvider: Failed to fetch credential summary', [
                    'student_id' => $studentId,
                    'status' => $response->status(),
                ]);
                return $this->defaultSummary();
            }

            $data = $response->json();

            return [
                'total_earned' => (int) ($data['total_earned'] ?? 0),
                'in_progress' => (int) ($data['in_progress'] ?? 0),
                'details' => $data['details'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('HttpCredentialDataProvider: Error fetching credential summary', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return $this->defaultSummary();
        }
    }
}
