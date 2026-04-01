<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Provides a reusable role-check guard for admin-only write actions.
 *
 * Screens 300-303 are school admin screens. All write operations
 * (create/edit/delete experiences, manage cohorts, enrol/remove students)
 * are admin-only. Teachers access data through their own teacher-facing
 * interfaces and have read-only access to these services.
 *
 * Trait name kept as RequiresTeacherAdmin for backwards compatibility
 * with existing controller references.
 */
trait RequiresTeacherAdmin
{
    /**
     * Returns a 403 response if the authenticated user is not a school admin.
     * Returns null if the user is authorized to proceed.
     */
    private function authorizeTeacherAdmin(string $action): ?JsonResponse
    {
        $role = Auth::user()->role;
        if ($role !== 'school_admin') {
            return response()->json([
                'error' => true,
                'message' => "Only school admins can {$action}",
                'code' => 'FORBIDDEN',
            ], 403);
        }
        return null;
    }
}
