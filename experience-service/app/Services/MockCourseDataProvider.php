<?php

/**
 * MockCourseDataProvider — Mock implementation of the Strategy pattern for course data.
 *
 * Architecture role:
 *   This is the concrete strategy for CourseDataProviderInterface during development.
 *   It provides a static, in-memory catalogue of 5 fake courses so that the Experience
 *   Service can:
 *     - Validate course IDs when creating/updating Experiences
 *     - Display course names in Experience detail views
 *     - Show course block structures on the Contents tab
 *   ...all without needing Team Papa's Course Service to be running.
 *
 * Strategy pattern (SDD Section 6.4):
 *   - Interface: CourseDataProviderInterface
 *   - This class: MockCourseDataProvider (development implementation)
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
     *   - blocks: Array of content blocks that make up the course structure
     *
     * Block fields match Karl's canonical schema: id, title (mapped to 'name'),
     * sequence, status (locked/active/complete). In production, blocks will also
     * contain nested 'nodes', which contain 'activity_cards' with card_type
     * (10 types: watch_video, read_document, complete_quiz, answer_question,
     * vote_poll, live_session, social_activity, explore_gallery,
     * listen_to_podcast, submit_solution). The mock omits nodes/cards since
     * Delta doesn't consume that level of detail yet.
     *
     * These 5 courses are sufficient for development and testing. Block counts
     * vary (1, 2, 3, 4, 5) to surface rendering bugs with variable-length content.
     * The IDs (1-5) are used in seed data and tests, so changing them requires
     * updating those too.
     */
    private static array $courses = [
        1 => [
            'id' => 1,
            'name' => 'Intro to Entrepreneurship',
            'description' => 'Learn the basics of starting and running a business.',
            'blocks' => [
                ['id' => 101, 'name' => 'What is a Business?', 'status' => 'complete'],
                ['id' => 102, 'name' => 'Business Models', 'status' => 'active'],
                ['id' => 103, 'name' => 'Business Plan Challenge', 'status' => 'locked'],
            ],
        ],
        2 => [
            'id' => 2,
            'name' => 'Financial Literacy',
            'description' => 'Understanding money, budgeting, and financial planning.',
            'blocks' => [
                ['id' => 201, 'name' => 'Budgeting Basics', 'status' => 'complete'],
                ['id' => 202, 'name' => 'Income and Expenses', 'status' => 'complete'],
                ['id' => 203, 'name' => 'Savings Challenge', 'status' => 'active'],
                ['id' => 204, 'name' => 'Investment Fundamentals', 'status' => 'locked'],
            ],
        ],
        3 => [
            'id' => 3,
            'name' => 'Marketing Basics',
            'description' => 'Introduction to marketing strategies and branding.',
            'blocks' => [
                ['id' => 301, 'name' => 'What is Marketing?', 'status' => 'active'],
                ['id' => 302, 'name' => 'Brand Identity Challenge', 'status' => 'active'],
            ],
        ],
        4 => [
            'id' => 4,
            'name' => 'Digital Skills',
            'description' => 'Building digital literacy and technical skills.',
            'blocks' => [
                ['id' => 401, 'name' => 'Internet Safety', 'status' => 'active'],
            ],
        ],
        5 => [
            'id' => 5,
            'name' => 'Coding Fundamentals',
            'description' => 'Introduction to programming concepts and logic.',
            'blocks' => [
                ['id' => 501, 'name' => 'Variables and Loops', 'status' => 'complete'],
                ['id' => 502, 'name' => 'Conditionals and Functions', 'status' => 'active'],
                ['id' => 503, 'name' => 'Build a Calculator Challenge', 'status' => 'locked'],
                ['id' => 504, 'name' => 'Data Structures', 'status' => 'active'],
                ['id' => 505, 'name' => 'Final Project', 'status' => 'active'],
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
