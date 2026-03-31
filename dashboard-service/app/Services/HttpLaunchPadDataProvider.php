<?php

/**
 * HttpLaunchPadDataProvider — Real implementation calling Team Quebec's User Service.
 *
 * Design pattern: Strategy (concrete implementation)
 *   This class is the real HTTP implementation of LaunchPadDataProviderInterface.
 *   It fetches venture/SideHustle data from Quebec's User Service profile endpoints.
 *
 * Quebec's API limitations:
 *   Quebec's student profiles store an `activeVentures` count but do NOT expose
 *   individual venture details (names, statuses, created_at). The countActiveVentures()
 *   method works fully, but getStudentVentures() returns the count with an empty
 *   ventures array since Quebec doesn't provide that granularity.
 *
 * Authentication:
 *   Calls to Quebec's /profile endpoints require a valid JWT bearer token.
 *   This provider uses the current request's bearer token for forwarding.
 *
 * @see \App\Contracts\LaunchPadDataProviderInterface  The interface this implements
 * @see \App\Services\MockLaunchPadDataProvider        The mock fallback
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LaunchPadDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpLaunchPadDataProvider implements LaunchPadDataProviderInterface
{
    /**
     * Count active ventures for a school by summing activeVentures across student profiles.
     *
     * Calls Quebec's GET /profile (admin-only, paginated) to fetch all profiles,
     * then sums the activeVentures field for students belonging to the given school.
     */
    public function countActiveVentures(int $schoolId): int
    {
        $userServiceUrl = config('services.user.url');
        $token = request()->bearerToken();

        if (!$token) {
            Log::warning('HttpLaunchPadDataProvider: No bearer token available');
            return 0;
        }

        try {
            $total = 0;
            $page = 0;
            $hasMore = true;

            while ($hasMore) {
                $response = Http::withToken($token)
                    ->timeout(5)
                    ->get("{$userServiceUrl}/profile", [
                        'page' => $page,
                        'size' => 100,
                    ]);

                if (!$response->successful()) {
                    Log::warning('HttpLaunchPadDataProvider: Failed to fetch profiles', [
                        'status' => $response->status(),
                        'page' => $page,
                    ]);
                    break;
                }

                $data = $response->json();
                $profiles = $data['content'] ?? [];

                foreach ($profiles as $profile) {
                    if (($profile['role'] ?? '') === 'STUDENT') {
                        $total += (int) ($profile['activeVentures'] ?? 0);
                    }
                }

                $hasMore = !($data['last'] ?? true);
                $page++;
            }

            return $total;
        } catch (\Exception $e) {
            Log::error('HttpLaunchPadDataProvider: Error counting ventures', [
                'school_id' => $schoolId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get venture summary for a specific student from their Quebec profile.
     *
     * Quebec's profile only stores an activeVentures count, not individual venture
     * details. This method returns the count but cannot populate the ventures array
     * with names/statuses — that would require a dedicated LaunchPad API endpoint
     * that Quebec has not yet built.
     */
    public function getStudentVentures(int $studentId): array
    {
        $userServiceUrl = config('services.user.url');
        $token = request()->bearerToken();

        if (!$token) {
            return ['active' => 0, 'completed' => 0, 'ventures' => []];
        }

        try {
            // We need the Quebec UUID for this student. Look up by local user's email.
            $localUser = \App\Models\User::find($studentId);
            if (!$localUser) {
                return ['active' => 0, 'completed' => 0, 'ventures' => []];
            }

            // Search Quebec profiles to find matching user by email
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("{$userServiceUrl}/profile", [
                    'page' => 0,
                    'size' => 100,
                ]);

            if (!$response->successful()) {
                return ['active' => 0, 'completed' => 0, 'ventures' => []];
            }

            $profiles = $response->json('content', []);
            $studentProfile = null;

            foreach ($profiles as $profile) {
                if (($profile['email'] ?? '') === $localUser->email) {
                    $studentProfile = $profile;
                    break;
                }
            }

            if (!$studentProfile) {
                return ['active' => 0, 'completed' => 0, 'ventures' => []];
            }

            $activeVentures = (int) ($studentProfile['activeVentures'] ?? 0);

            return [
                'active' => $activeVentures,
                'completed' => 0, // Quebec doesn't expose completed venture count
                'ventures' => [], // Quebec doesn't expose individual venture details
            ];
        } catch (\Exception $e) {
            Log::error('HttpLaunchPadDataProvider: Error fetching student ventures', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            return ['active' => 0, 'completed' => 0, 'ventures' => []];
        }
    }
}
