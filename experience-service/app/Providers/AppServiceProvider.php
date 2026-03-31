<?php

/**
 * AppServiceProvider — Dependency injection bindings for the Experience Service.
 *
 * This is where the Strategy pattern is wired. The service container is Laravel's
 * implementation of Inversion of Control (IoC): instead of classes creating their
 * own dependencies, the container injects them. This provider tells the container
 * WHICH concrete class to inject when a controller or service asks for an interface.
 *
 * AUTH_MODE toggle (Strategy pattern):
 *   The AUTH_MODE environment variable controls which CourseDataProvider
 *   implementation is injected throughout the application:
 *
 *   CourseDataProviderInterface:
 *     'http' (default) -> HttpCourseDataProvider  (calls Team Papa's Course Service)
 *     'mock'           -> MockCourseDataProvider  (returns 5 hardcoded courses)
 *
 *   When a controller or service has a constructor parameter typed as
 *   CourseDataProviderInterface, Laravel sees this binding and automatically
 *   injects the correct implementation. The consuming class never knows (or
 *   cares) which concrete implementation it received.
 *
 * @see \App\Contracts\CourseDataProviderInterface  The Strategy pattern interface
 * @see \App\Services\MockCourseDataProvider        Mock implementation (dev/testing)
 * @see \App\Services\HttpCourseDataProvider        HTTP implementation (production)
 */

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CourseDataProviderInterface;
use App\Services\HttpCourseDataProvider;
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
        $this->app->bind(
            CourseDataProviderInterface::class,
            env('AUTH_MODE', 'http') === 'http'
                ? HttpCourseDataProvider::class
                : MockCourseDataProvider::class
        );
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
