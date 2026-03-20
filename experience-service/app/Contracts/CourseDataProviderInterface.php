<?php

/**
 * CourseDataProviderInterface — Strategy pattern interface for accessing the course catalogue.
 *
 * Architecture role:
 *   This interface is the key abstraction that decouples the Experience Service from
 *   Team Papa's Course Service. Instead of making direct HTTP calls to the Course Service
 *   throughout the codebase, all course data access goes through this interface.
 *
 * Strategy pattern (SDD Section 6.4):
 *   - Interface: CourseDataProviderInterface (this file)
 *   - Mock implementation: MockCourseDataProvider (static in-memory data)
 *   - Future concrete strategy: HttpCourseDataProvider (real HTTP calls to Team Papa's API)
 *
 * How swapping works:
 *   The binding is configured in AppServiceProvider::register(). To switch from mock
 *   to real, you only change ONE line:
 *     $this->app->bind(CourseDataProviderInterface::class, HttpCourseDataProvider::class);
 *   No controller or service code needs to change — they all depend on the interface,
 *   not the concrete class. This is the Dependency Inversion Principle (SOLID "D").
 *
 * Why this matters for testing:
 *   In PHPUnit tests, you can bind a test double to this interface to control exactly
 *   what course data is returned, without needing a running Course Service. Example:
 *     $this->app->bind(CourseDataProviderInterface::class, FakeProvider::class);
 *
 * @see \App\Services\MockCourseDataProvider   Mock implementation with hardcoded data
 * @see \App\Providers\AppServiceProvider      Where the binding is configured
 */

declare(strict_types=1);

namespace App\Contracts;

interface CourseDataProviderInterface
{
    /**
     * Return the full course catalogue as an indexed array of course arrays.
     *
     * Each course array has: id, name, description, blocks.
     * Each block has: id, name, status (locked/active/complete).
     * In production, blocks will also contain nested nodes and activity_cards.
     *
     * @return array<int, array{id: int, name: string, description: string, blocks: array}>
     */
    public function getAllCourses(): array;

    /**
     * Return a single course by ID, or null if the course does not exist.
     *
     * @return array{id: int, name: string, description: string, blocks: array}|null
     */
    public function getCourse(int $id): ?array;

    /**
     * Check whether a course ID exists in the catalogue.
     *
     * Used by ExperienceService::validateCourseIds() before creating or updating
     * an Experience, to ensure all referenced courses are valid.
     */
    public function courseExists(int $id): bool;

    /**
     * Return courses matching a list of IDs (for batch lookups).
     *
     * @param  array<int> $ids
     * @return array<int, array{id: int, name: string, description: string, blocks: array}>
     */
    public function getCoursesByIds(array $ids): array;
}
