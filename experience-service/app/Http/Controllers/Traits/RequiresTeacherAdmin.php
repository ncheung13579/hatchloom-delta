<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Provides a reusable role-check guard for admin/teacher write actions.
 *
 * Per the workpack, teachers "build the Experience and run the Cohorts",
 * so experience CRUD and cohort management require admin OR teacher role.
 * Only student enrol/remove is admin-only (handled separately in
 * EnrolmentController with its own guard).
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
