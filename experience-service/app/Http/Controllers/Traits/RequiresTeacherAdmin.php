<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Provides a reusable role-check guard for admin/teacher-only actions.
 *
 * Eliminates the repeated 7-line role-check block that was copy-pasted
 * across ExperienceController (store, update, destroy).
 */
trait RequiresTeacherAdmin
{
    /**
     * Returns a 403 response if the authenticated user is not a teacher or admin.
     * Returns null if the user is authorized to proceed.
     */
    private function authorizeTeacherAdmin(string $action): ?JsonResponse
    {
        $role = Auth::user()->role;
        if (!in_array($role, ['school_teacher', 'school_admin'], true)) {
            return response()->json([
                'error' => true,
                'message' => "Only school teachers and admins can {$action}",
                'code' => 'FORBIDDEN',
            ], 403);
        }
        return null;
    }
}
