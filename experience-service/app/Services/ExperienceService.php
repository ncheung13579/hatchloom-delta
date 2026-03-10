<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Experience;
use App\Models\ExperienceCourse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class ExperienceService
{
    public function __construct(
        private readonly MockCourseDataProvider $courseDataProvider
    ) {}

    public function listExperiences(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = Experience::query()->with(['courses', 'creator']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getExperience(int $id): ?Experience
    {
        return Experience::with(['courses', 'creator'])->find($id);
    }

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
