<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CourseDataProviderInterface;
use App\Services\MockCourseDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Binds the CourseDataProviderInterface to the mock implementation for D1.
     * When Team Papa's Course Service is available, swap MockCourseDataProvider
     * for the real HTTP-backed implementation here — no other code changes needed.
     */
    public function register(): void
    {
        $this->app->bind(CourseDataProviderInterface::class, MockCourseDataProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
