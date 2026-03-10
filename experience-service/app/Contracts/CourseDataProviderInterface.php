<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for accessing course catalogue data.
 *
 * In D1, this is fulfilled by MockCourseDataProvider with static in-memory
 * data. When Team Papa's Course Service is available, a real HTTP-backed
 * implementation will replace the mock via the service container binding
 * in AppServiceProvider.
 */
interface CourseDataProviderInterface
{
    /** Return the full course catalogue. */
    public function getAllCourses(): array;

    /** Return a single course by ID, or null if not found. */
    public function getCourse(int $id): ?array;

    /** Check whether a course ID exists in the catalogue. */
    public function courseExists(int $id): bool;

    /** Return courses matching a list of IDs. */
    public function getCoursesByIds(array $ids): array;
}
