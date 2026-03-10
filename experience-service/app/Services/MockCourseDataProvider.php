<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CourseDataProviderInterface;

/**
 * Strategy pattern placeholder for Team Papa's Course Service.
 *
 * Provides a static in-memory catalogue of mock courses so that the
 * Experience Service can validate course IDs and display course details
 * without a live dependency on the Course Service. When Team Papa's API
 * is available, swap the binding in AppServiceProvider to point at the
 * real implementation instead.
 */
class MockCourseDataProvider implements CourseDataProviderInterface
{
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

    public function getAllCourses(): array
    {
        return array_values(self::$courses);
    }

    public function getCourse(int $id): ?array
    {
        return self::$courses[$id] ?? null;
    }

    public function courseExists(int $id): bool
    {
        return isset(self::$courses[$id]);
    }

    public function getCoursesByIds(array $ids): array
    {
        return array_values(array_filter(
            self::$courses,
            fn(array $course) => in_array($course['id'], $ids)
        ));
    }
}
