<?php

/**
 * AppServiceProvider — Dependency injection bindings for the Experience Service.
 *
 * Architecture role:
 *   This is where Laravel's service container is configured. The service container is
 *   Laravel's implementation of Inversion of Control (IoC): instead of classes creating
 *   their own dependencies, the container injects them. This provider tells the container
 *   WHICH concrete class to inject when a controller or service asks for an interface.
 *
 * Strategy pattern wiring:
 *   The most important binding here is CourseDataProviderInterface -> MockCourseDataProvider.
 *   This single line controls which course data source the entire application uses.
 *
 *   When a controller or service has a constructor parameter typed as
 *   CourseDataProviderInterface, Laravel sees this binding and automatically creates
 *   a MockCourseDataProvider instance to inject. This happens transparently — the
 *   consuming class never knows (or cares) which concrete implementation it received.
 *
 * How to swap to real course data:
 *   When Team Papa's Course Service is ready, create a new class (e.g., HttpCourseDataProvider)
 *   that implements CourseDataProviderInterface and makes real HTTP calls. Then change ONE line:
 *     $this->app->bind(CourseDataProviderInterface::class, HttpCourseDataProvider::class);
 *   All controllers and services will automatically receive the new implementation.
 *
 * @see \App\Contracts\CourseDataProviderInterface  The interface being bound
 * @see \App\Services\MockCourseDataProvider        The current mock implementation
 */

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CourseDataProviderInterface;
use App\Services\MockCourseDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register service container bindings.
     *
     * bind() creates a new instance each time the interface is resolved. If we wanted
     * a singleton (same instance reused), we'd use $this->app->singleton() instead.
     * For the mock provider, bind() is fine since the static data is shared via the
     * class-level static property anyway.
     */
    public function register(): void
    {
        $this->app->bind(CourseDataProviderInterface::class, MockCourseDataProvider::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Currently empty. This is where you'd register event listeners, observers,
     * or other boot-time configuration that depends on all providers being registered.
     */
    public function boot(): void
    {
        //
    }
}
