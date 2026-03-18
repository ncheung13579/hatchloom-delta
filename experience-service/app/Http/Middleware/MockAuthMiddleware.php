<?php

/**
 * MockAuthMiddleware — D1 mock authentication for the Experience Service.
 *
 * Architecture role:
 *   This middleware sits at the front of the request lifecycle for all protected
 *   routes (registered as 'mock.auth' in the route middleware stack). It runs
 *   BEFORE the request reaches any controller, and its job is to:
 *     1. Extract the Bearer token from the Authorization header
 *     2. Map it to a seeded User record via a hardcoded lookup table
 *     3. Log that user into Laravel's Auth system so request->user() works everywhere
 *     4. Enforce role-based access (only school_admin and school_teacher may proceed)
 *
 * Why mock auth?
 *   Real authentication is owned by Team Quebec. For D1, all four teams agreed on a
 *   shared mock: hardcoded tokens map to seeded users. This lets us develop and test
 *   all endpoints without waiting for Quebec's auth service. The middleware will be
 *   swapped for a real JWT/OAuth integration in a later deliverable.
 *
 * Request lifecycle position:
 *   HTTP Request -> MockAuthMiddleware -> AuditLogMiddleware -> Controller -> Response
 *
 * Security note:
 *   Once Auth::login($user) is called, the SchoolScope global scope on models like
 *   Experience can read Auth::user()->school_id to enforce tenant isolation. This is
 *   why authentication MUST happen before any database query.
 *
 * @see \App\Models\Scopes\SchoolScope  Depends on Auth::user() being set by this middleware
 * @see routes/api.php                  Where this middleware is applied to route groups
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
     * Hardcoded token-to-user-ID mapping for D1.
     *
     * These user IDs correspond to records seeded by the database seeder:
     *   - ID 1: School admin (school_id=1, role=school_admin)
     *   - ID 2: Teacher (school_id=1, role=school_teacher)
     *
     * Any token not in this map is rejected with 401 Unauthenticated.
     */
    private const TOKEN_MAP = [
        'test-admin-token' => 1,
        'test-teacher-token' => 2,
        'test-student-token' => 4,
    ];

    /**
     * Authenticate the request using the Bearer token, then authorize the user's role.
     *
     * Three possible outcomes:
     *   - 401 Unauthenticated: missing/invalid token, or user record not found in DB
     *   - 403 Forbidden: valid user but wrong role (e.g., a student token somehow)
     *   - Pass through: user is authenticated and authorized, request continues to controller
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        // Reject if no token provided or token is not in our known set.
        if (!$token || !isset(self::TOKEN_MAP[$token])) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Look up the user in the database. This could fail if the seeder hasn't run,
        // which is why we check for null separately from the token check above.
        $user = User::find(self::TOKEN_MAP[$token]);

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'Unauthenticated',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Set the authenticated user globally. After this call:
        //   - Auth::user() returns this user
        //   - request()->user() returns this user
        //   - SchoolScope can read school_id for tenant filtering
        Auth::login($user);

        // Role-based authorization: only school admins and teachers can use the
        // Experience Service. Students and other roles get a 403 Forbidden.
        $allowedRoles = ['school_admin', 'school_teacher'];
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
