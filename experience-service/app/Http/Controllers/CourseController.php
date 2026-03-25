<?php

/**
 * CourseController — Read-only endpoint for the course catalogue.
 *
 * Architecture role:
 *   Exposes the course catalogue (from Team Papa's Course Service) so that frontends
 *   can populate course selection dropdowns when creating or editing an Experience.
 *   Delegates entirely to the CourseDataProviderInterface (Strategy pattern), so the
 *   same swap-one-line approach in AppServiceProvider works here too.
 *
 * Access control:
 *   Admin and teacher only — students and parents do not need to browse the raw
 *   course catalogue. They see course data contextually via Experience detail/contents.
 *
 * @see \App\Contracts\CourseDataProviderInterface  Strategy interface for course data
 * @see \App\Services\MockCourseDataProvider        Current (mock) implementation
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\CourseDataProviderInterface;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function __construct(
        private readonly CourseDataProviderInterface $courseDataProvider
    ) {}

    /**
     * GET /api/school/courses — List all available courses in the catalogue.
     *
     * Returns the full course catalogue with block structures. Used by the
     * Experience creation form to let teachers select which courses to include.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->courseDataProvider->getAllCourses(),
        ]);
    }
}
