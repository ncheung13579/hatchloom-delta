<?php

declare(strict_types=1);

/**
 * MockAuthMiddleware — Mock authentication for the Enrolment Service.
 *
 * In the Hatchloom architecture, real authentication is owned by Team Quebec.
 * Currently, all four teams use hardcoded bearer tokens mapped to seeded User records.
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
 * Mock authentication middleware.
 *
 * Maps hardcoded bearer tokens to seeded User records, bypassing real
 * authentication until Team Quebec's auth service is integrated. Rejects requests with missing
 * or unrecognized tokens (401) and restricts access to school_admin and
 * school_teacher roles (403). Uses the same pattern as experience-service.
 *
 * This will be replaced when Team Quebec's real auth service is integrated.
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
        'test-student-token' => 4,
        'test-parent-token' => 14,
        'test-hatchloom-teacher-token' => 15,
        'test-hatchloom-admin-token' => 16,
    ];

    /**
     * Authenticate the request and enforce role-based access control.
     *
     * Accepts optional extra roles as middleware parameters. By default only
     * school_admin and school_teacher are allowed. Routes can opt in to
     * additional roles, e.g.: middleware('mock.auth:student') to also
     * allow the student role on read-only endpoints.
     */
    public function handle(Request $request, Closure $next, string ...$extraRoles): Response
    {
        $token = $request->bearerToken();

        if (!$token || !isset(self::TOKEN_MAP[$token])) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $user = User::find(self::TOKEN_MAP[$token]);

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        Auth::login($user);

        // Role-based access control: school_admin and school_teacher are always
        // allowed. Additional roles can be granted per-route via middleware params.
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
}
