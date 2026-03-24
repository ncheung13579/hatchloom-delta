<?php

/**
 * ExperienceService — Business logic layer for Experience CRUD (Screen 301).
 *
 * Architecture role:
 *   This is the service layer in the Controller -> Service -> Model pattern. It sits
 *   between ExperienceController and the Experience/ExperienceCourse Eloquent models.
 *   All business logic for creating, listing, updating, and deleting Experiences lives
 *   here — controllers only handle HTTP concerns (validation, response formatting).
 *
 *   Also used by ExperienceScreenController (Screen 302) for Experience lookups, since
 *   Screen 302 endpoints need to validate the parent Experience exists before fetching
 *   sub-resources.
 *
 * Design patterns:
 *   - Repository pattern: This service acts as the repository boundary. Controllers never
 *     call Eloquent directly — they always go through this service.
 *   - Strategy pattern: Course ID validation uses the injected CourseDataProviderInterface
 *     rather than hardcoding a data source. This makes the service testable and ready for
 *     the real Course Service integration.
 *
 * School scoping:
 *   All Experience queries are automatically filtered by school_id via the SchoolScope
 *   global scope. This service does NOT need to manually add WHERE school_id = ? clauses.
 *   The only place school_id is explicitly set is in createExperience(), where we read it
 *   from Auth::user() to populate the new record.
 *
 * @see \App\Http\Controllers\ExperienceController        Primary consumer
 * @see \App\Http\Controllers\ExperienceScreenController   Also uses getExperience()
 * @see \App\Contracts\CourseDataProviderInterface          Strategy for course validation
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;
use App\Models\Experience;
use App\Models\ExperienceCourse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class ExperienceService
{
    public function __construct(
        private readonly CourseDataProviderInterface $courseDataProvider
    ) {}

    /**
     * List experiences with optional name search and pagination.
     *
     * Results are automatically scoped to the authenticated user's school
     * via the SchoolScope global scope on the Experience model.
     */
    public function listExperiences(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = Experience::query()->with(['courses', 'creator']);

        if ($search) {
            $searchLower = mb_strtolower($search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Find a single Experience by ID, eager-loading its courses and creator.
     *
     * Returns null if the Experience doesn't exist OR belongs to a different school
     * (SchoolScope filters it out transparently). The caller should treat null as
     * "not found" without distinguishing between non-existent and wrong-school.
     */
    public function getExperience(int $id): ?Experience
    {
        return Experience::with(['courses', 'creator'])->find($id);
    }

    /**
     * Create an Experience and attach its courses in sequence order.
     *
     * Course IDs should be validated via validateCourseIds() before calling
     * this method. Each course is stored as an ExperienceCourse pivot record
     * with a 1-based sequence derived from array position.
     */
    public function createExperience(array $data): Experience
    {
        $experience = Experience::create([
            'school_id' => Auth::user()->school_id,
            'name' => $data['name'],
            'description' => $data['description'],
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        ExperienceCourse::insert(array_map(fn($courseId, $index) => [
            'experience_id' => $experience->id,
            'course_id' => $courseId,
            'sequence' => $index + 1,
        ], $data['course_ids'], array_keys($data['course_ids'])));

        return $experience->load('courses');
    }

    /**
     * Update an Experience's metadata and optionally replace its course list.
     *
     * If course_ids is provided, existing course associations are deleted
     * and replaced entirely (full replacement, not a merge) to keep
     * sequencing consistent.
     */
    public function updateExperience(Experience $experience, array $data): Experience
    {
        if (isset($data['name'])) {
            $experience->name = $data['name'];
        }
        if (isset($data['description'])) {
            $experience->description = $data['description'];
        }
        $experience->save();

        if (isset($data['course_ids'])) {
            $experience->courses()->delete();
            ExperienceCourse::insert(array_map(fn($courseId, $index) => [
                'experience_id' => $experience->id,
                'course_id' => $courseId,
                'sequence' => $index + 1,
            ], $data['course_ids'], array_keys($data['course_ids'])));
        }

        return $experience->load('courses');
    }

    /**
     * Soft-delete an Experience by marking it as archived first.
     *
     * Sets status to 'archived' before soft-deleting so the record is
     * preserved for audit purposes but excluded from active queries.
     */
    public function deleteExperience(Experience $experience): void
    {
        $experience->update(['status' => 'archived']);
        $experience->delete();
    }

    /**
     * Validate that every course ID in the array exists in the upstream catalogue.
     *
     * Uses the CourseDataProviderInterface (Strategy pattern) so validation works
     * identically whether we're checking against mock data or a real HTTP service.
     * Returns false on the FIRST invalid ID found (fail-fast).
     */
    public function validateCourseIds(array $courseIds): bool
    {
        foreach ($courseIds as $id) {
            if (!$this->courseDataProvider->courseExists((int) $id)) {
                return false;
            }
        }
        return true;
    }
}
