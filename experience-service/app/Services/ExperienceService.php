<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;
use App\Models\Experience;
use App\Models\ExperienceCourse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Service layer for Experience CRUD operations (Screen 301).
 *
 * Encapsulates all business logic for creating, listing, updating, and
 * deleting Experiences. Controllers delegate here to keep themselves thin.
 * Course ID validation is performed against a CourseDataProviderInterface
 * implementation, which can be swapped from mock to real via the service
 * container binding in AppServiceProvider.
 */
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

        foreach ($data['course_ids'] as $sequence => $courseId) {
            ExperienceCourse::create([
                'experience_id' => $experience->id,
                'course_id' => $courseId,
                'sequence' => $sequence + 1,
            ]);
        }

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
            foreach ($data['course_ids'] as $sequence => $courseId) {
                ExperienceCourse::create([
                    'experience_id' => $experience->id,
                    'course_id' => $courseId,
                    'sequence' => $sequence + 1,
                ]);
            }
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
