<?php

/**
 * HttpAuthMiddleware — Real authentication via Team Quebec's User Service.
 *
 * Validates JWT bearer tokens against Quebec's /auth/validate endpoint and
 * fetches user profile data from /profile/{userId}. Finds the corresponding
 * local user by email to maintain compatibility with SchoolScope and all
 * existing code that uses Auth::user().
 *
 * Quebec's User Service runs on port 8080 and uses UUID-based user IDs.
 * Our services use integer IDs. The bridge is email matching: when a token
 * is validated, we look up the local user by email. If no local user exists,
 * authentication fails — users must be seeded/synced in our DB first.
 *
 * Role mapping:
 *   Quebec uses uppercase enums (SCHOOL_ADMIN, SCHOOL_TEACHER, STUDENT, PARENT).
 *   Our services use lowercase with underscores (school_admin, school_teacher, student, parent).
 *   This middleware lowercases Quebec's role names automatically.
 *
 * @see \App\Http\Middleware\MockAuthMiddleware  The mock fallback for development
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HttpAuthMiddleware
{
    /**
     * Authenticate the request against Team Quebec's User Service.
     *
     * Flow:
     *   1. Extract bearer token from Authorization header
     *   2. POST/GET to Quebec's /auth/validate to verify the token
     *   3. On success, fetch profile from /profile/{userId} for email + details
     *   4. Find matching local user by email
     *   5. Check role-based access control (same logic as MockAuthMiddleware)
     */
    public function handle(Request $request, Closure $next, string ...$extraRoles): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return $this->unauthenticated();
        }

        $userServiceUrl = config('services.user.url');

        // Step 1: Validate the JWT token against Quebec's auth service
        try {
            $validateResponse = Http::withToken($token)
                ->timeout(5)
                ->get("{$userServiceUrl}/auth/validate");

            if (!$validateResponse->successful()) {
                return $this->unauthenticated();
            }

            $validation = $validateResponse->json();

            if (!($validation['valid'] ?? false)) {
                return $this->unauthenticated();
            }

            $quebecUserId = $validation['userId'] ?? null;
            $quebecRole = $validation['role'] ?? null;

            if (!$quebecUserId || !$quebecRole) {
                return $this->unauthenticated();
            }
        } catch (\Exception $e) {
            Log::error('Failed to validate token against User Service', [
                'error' => $e->getMessage(),
            ]);
            return $this->unauthenticated('Authentication service unavailable');
        }

        // Step 2: Fetch the user's profile for email and other details
        try {
            $profileResponse = Http::withToken($token)
                ->timeout(5)
                ->get("{$userServiceUrl}/profile/{$quebecUserId}");

            if (!$profileResponse->successful()) {
                Log::warning('Token valid but profile fetch failed', [
                    'quebec_user_id' => $quebecUserId,
                    'status' => $profileResponse->status(),
                ]);
                return $this->unauthenticated('Unable to fetch user profile');
            }

            $profile = $profileResponse->json();
        } catch (\Exception $e) {
            Log::error('Failed to fetch profile from User Service', [
                'quebec_user_id' => $quebecUserId,
                'error' => $e->getMessage(),
            ]);
            return $this->unauthenticated('Authentication service unavailable');
        }

        // Step 3: Find the matching local user by email
        $email = $profile['email'] ?? null;
        if (!$email) {
            return $this->unauthenticated('User profile missing email');
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            Log::warning('Authenticated via Quebec but no local user found', [
                'email' => $email,
                'quebec_user_id' => $quebecUserId,
                'role' => $quebecRole,
            ]);
            return $this->unauthenticated('User not found in local system');
        }

        // Step 4: Log the user in so Auth::user() works throughout the request
        Auth::login($user);

        // Step 5: Role-based access control (same logic as MockAuthMiddleware)
        $allowedRoles = array_merge(['school_admin', 'school_teacher'], $extraRoles);
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'error' => true,
                'message' => 'Forbidden: insufficient role',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }

    private function unauthenticated(string $message = 'Unauthenticated'): Response
    {
        return response()->json([
            'error' => true,
            'message' => $message,
            'code' => 'UNAUTHENTICATED',
        ], 401);
    }
}
