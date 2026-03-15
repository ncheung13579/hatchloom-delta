<?php

declare(strict_types=1);

/**
 * MockAuthMiddleware — D1 mock authentication for the Enrolment Service.
 *
 * In the Hatchloom architecture, real authentication is owned by Team Quebec.
 * For D1, all four teams use hardcoded bearer tokens mapped to seeded User records.
 * This middleware intercepts every protected request, validates the token, logs
 * the user into Laravel's Auth system, and enforces role-based access control.
 *
 * Request lifecycle:
 *  1. Extract the Bearer token from the Authorization header
 *  2. Look up the token in TOKEN_MAP to get a user ID
 *  3. Load the User model from the database
 *  4. Log the user in via Auth::login() so request->user() works downstream
 *  5. Check the user's role is allowed (school_admin or school_teacher)
 *  6. Pass the request to the next middleware / controller
 *
 * This middleware is registered as 'mock.auth' in the kernel and applied to
 * all protected routes in routes/api.php. It will be replaced by Team Quebec's
 * real auth integration in a later deliverable.
 *
 * @see routes/api.php  Where this middleware is applied to route groups
 */

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D1 mock authentication middleware.
 *
 * Maps hardcoded bearer tokens to seeded User records, bypassing real
 * authentication for the first deliverable. Rejects requests with missing
 * or unrecognized tokens (401) and restricts access to school_admin and
 * school_teacher roles (403). Uses the same pattern as experience-service.
 *
 * This will be replaced by Team Quebec's real auth service in a later deliverable.
 */
class MockAuthMiddleware
{
    /**
     * Hardcoded token-to-user-ID mapping.
     *
     * These user IDs must match the seeded users in the database:
     *  - User 1: school admin (school_id=1, role=school_admin)
     *  - User 2: teacher (school_id=1, role=school_teacher)
     *
     * Any token not in this map is rejected with HTTP 401.
     */
    private const TOKEN_MAP = [
        'test-admin-token' => 1,
        'test-teacher-token' => 2,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Step 1-2: Extract bearer token and look up in the hardcoded map.
        // bearerToken() returns null if no Authorization: Bearer header is present.
        $token = $request->bearerToken();

        if (!$token || !isset(self::TOKEN_MAP[$token])) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Step 3: Load the User from the database. This could fail if the seed
        // data is missing, so we treat a missing user the same as a bad token.
        $user = User::find(self::TOKEN_MAP[$token]);

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Step 4: Log the user into Laravel's auth system. This makes
        // Auth::user() and $request->user() return this user for the
        // remainder of the request. Crucially, this also enables the
        // SchoolScope global scope to read the authenticated user's school_id.
        Auth::login($user);

        // Step 5: Role-based access control. Only school admins and teachers
        // can access the Enrolment Service endpoints. Students and other roles
        // are blocked with HTTP 403 Forbidden.
        $allowedRoles = ['school_admin', 'school_teacher'];
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'error' => true,
                'message' => 'Forbidden: insufficient role',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        // Step 6: All checks passed — forward to the next middleware or controller.
        return $next($request);
    }
}
