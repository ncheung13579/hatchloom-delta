<?php

/**
 * MockCourseDataProvider — D1 mock implementation of the Strategy pattern for course data.
 *
 * Architecture role:
 *   This is the concrete strategy for CourseDataProviderInterface during D1 development.
 *   It provides a static, in-memory catalogue of 5 fake courses so that the Experience
 *   Service can:
 *     - Validate course IDs when creating/updating Experiences
 *     - Display course names in Experience detail views
 *     - Show course block structures (lessons/challenges) on the Contents tab
 *   ...all without needing Team Papa's Course Service to be running.
 *
 * Strategy pattern (SDD Section 6.4):
 *   - Interface: CourseDataProviderInterface
 *   - This class: MockCourseDataProvider (D1 concrete strategy)
 *   - Future replacement: HttpCourseDataProvider (makes real HTTP calls to Team Papa's API)
 *
 * How to swap to a real provider:
 *   1. Create a new class implementing CourseDataProviderInterface that makes HTTP calls
 *      to Team Papa's Course Service (e.g., GET http://course-service:8004/api/courses)
 *   2. Change the binding in AppServiceProvider::register():
 *        $this->app->bind(CourseDataProviderInterface::class, HttpCourseDataProvider::class);
 *   3. No other code changes needed — all consumers depend on the interface, not this class.
 *
 * Testing benefit:
 *   Because this is injected via the service container, tests can easily swap in a
 *   custom mock that returns specific course data for test scenarios.
 *
 * @see \App\Contracts\CourseDataProviderInterface  The interface this implements
 * @see \App\Providers\AppServiceProvider           Where the binding is registered
 */

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;

class MockCourseDataProvider implements CourseDataProviderInterface
{
    /**
     * Static in-memory course catalogue.
     *
     * Keyed by course ID for O(1) lookups. Each course has:
     *   - id: Unique identifier (matches what Team Papa's real service would return)
     *   - name: Display name shown in Experience detail views
     *   - description: Summary text for the course
     *   - blocks: Array of lesson/challenge items that make up the course content
     *
     * These 5 courses are sufficient for D1 development and testing. The IDs (1-5)
     * are used in seed data and tests, so changing them requires updating those too.
     */
    private static array $courses = [
        1 => [
            'id' => 1,
            'name' => 'Intro to Entrepreneurship',
            'description' => 'Learn the basics of starting and running a business.',
            'blocks' => [
                ['id' => 101, 'title' => 'What is a Business?', 'type' => 'lesson'],
                ['id' => 102, 'title' => 'Business Plan Challenge', 'type' => 'challenge'],
            ],
        ],
        2 => [
            'id' => 2,
            'name' => 'Financial Literacy',
            'description' => 'Understanding money, budgeting, and financial planning.',
            'blocks' => [
                ['id' => 201, 'title' => 'Budgeting Basics', 'type' => 'lesson'],
                ['id' => 202, 'title' => 'Savings Challenge', 'type' => 'challenge'],
            ],
        ],
        3 => [
            'id' => 3,
            'name' => 'Marketing Basics',
            'description' => 'Introduction to marketing strategies and branding.',
            'blocks' => [
                ['id' => 301, 'title' => 'What is Marketing?', 'type' => 'lesson'],
                ['id' => 302, 'title' => 'Brand Identity Challenge', 'type' => 'challenge'],
            ],
        ],
        4 => [
            'id' => 4,
            'name' => 'Digital Skills',
            'description' => 'Building digital literacy and technical skills.',
            'blocks' => [
                ['id' => 401, 'title' => 'Internet Safety', 'type' => 'lesson'],
                ['id' => 402, 'title' => 'Digital Portfolio Challenge', 'type' => 'challenge'],
            ],
        ],
        5 => [
            'id' => 5,
            'name' => 'Coding Fundamentals',
            'description' => 'Introduction to programming concepts and logic.',
            'blocks' => [
                ['id' => 501, 'title' => 'Variables and Loops', 'type' => 'lesson'],
                ['id' => 502, 'title' => 'Build a Calculator Challenge', 'type' => 'challenge'],
            ],
        ],
    ];

    /** Return all 5 mock courses as a flat indexed array (strips the ID keys). */
    public function getAllCourses(): array
    {
        return array_values(self::$courses);
    }

    /** O(1) lookup by course ID. Returns null for any ID not in {1..5}. */
    public function getCourse(int $id): ?array
    {
        return self::$courses[$id] ?? null;
    }

    /** Quick existence check — used by ExperienceService::validateCourseIds(). */
    public function courseExists(int $id): bool
    {
        return isset(self::$courses[$id]);
    }

    /** Batch lookup — filters the catalogue to only include courses whose IDs are in the list. */
    public function getCoursesByIds(array $ids): array
    {
        return array_values(array_filter(
            self::$courses,
            fn(array $course) => in_array($course['id'], $ids)
        ));
    }
}
