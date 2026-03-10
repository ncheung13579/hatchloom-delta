<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D1 mock authentication middleware.
 *
 * Maps hardcoded bearer tokens to seeded users instead of validating real JWTs.
 * This is the same pattern used across all three services. In production, this
 * will be replaced by Team Quebec's auth service integration.
 */
class MockAuthMiddleware
{
    private const TOKEN_MAP = [
        'test-admin-token' => 1,
        'test-teacher-token' => 2,
    ];

    public function handle(Request $request, Closure $next): Response
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
