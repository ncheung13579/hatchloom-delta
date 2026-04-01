<?php

/**
 * MockAuthMiddleware — Mock authentication for the Dashboard Service.
 *
 * Request lifecycle position:
 *   This middleware runs BEFORE the request reaches any controller. It is
 *   registered as 'auth.role' in the kernel and applied to all dashboard
 *   endpoints except the health check (see routes/api.php).
 *
 * What it does:
 *   1. Extracts the Bearer token from the Authorization header
 *   2. Looks up the token in a hardcoded map to find the corresponding user ID
 *   3. Loads the User model from the database and logs them in via Auth::login()
 *   4. Checks that the user's role is school_admin (default) or any extra roles passed to middleware
 *   5. If any step fails, returns a 401 (Unauthenticated) or 403 (Forbidden)
 *
 * Why mock auth?
 *   Currently, Team Quebec's real authentication service is not yet integrated.
 *   All three Delta services (Dashboard, Experience, Enrolment) use the same
 *   mock auth pattern with identical token mappings, so a request can flow
 *   through the entire microservice chain with a single bearer token.
 *
 * Production replacement:
 *   When Team Quebec's auth service is ready, replace this middleware with one
 *   that validates real JWTs against their auth endpoint. The rest of the
 *   codebase uses Auth::user() and request()->bearerToken() generically, so
 *   no other code needs to change.
 *
 * @see routes/api.php  Where this middleware is applied via 'auth.role'
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class MockAuthMiddleware
{
    /**
     * Maps bearer token strings to seeded user IDs in the database.
     *
     * User ID 1 = school admin (role: school_admin, school_id: 1)
     * User ID 2 = teacher (role: school_teacher, school_id: 1)
     *
     * These IDs must match the seed data in the database migration/seeder.
     * The same mapping is used by all three Delta microservices so that
     * forwarded tokens (e.g., Dashboard -> Experience) remain valid.
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
     * additional roles, e.g.: middleware('auth.role:student') to also
     * allow the student role on read-only endpoints.
     */
    public function handle(Request $request, Closure $next, string ...$extraRoles): Response
    {
        $token = $request->bearerToken();

        // Reject requests with no token or an unrecognized token
        if (!$token || !isset(self::TOKEN_MAP[$token])) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Look up the seeded user in the database. This could fail if the DB
        // hasn't been seeded, so we check for null explicitly.
        $user = User::find(self::TOKEN_MAP[$token]);

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Set the authenticated user so downstream code can use Auth::user()
        // and $request->user() to access the current user's identity and school_id
        Auth::login($user);

        // Role-based access control: only school_admin is allowed by default.
        // The dashboard overview and reporting screens are admin-only (per the
        // Hatchloom reference screens). Additional roles can be granted per-route
        // via middleware params, e.g. auth.role:school_teacher,student,parent
        // for the student drill-down endpoint.
        $allowedRoles = array_merge(['school_admin'], $extraRoles);
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
