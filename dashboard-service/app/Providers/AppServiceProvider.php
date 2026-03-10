<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CredentialDataProviderInterface;
use App\Contracts\StudentProgressProviderInterface;
use App\Services\MockCredentialDataProvider;
use App\Services\MockStudentProgressProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Binds provider interfaces to their mock implementations for D1.
     * When real upstream services are available, swap the mock classes
     * here — no other code changes needed.
     */
    public function register(): void
    {
        $this->app->bind(CredentialDataProviderInterface::class, MockCredentialDataProvider::class);
        $this->app->bind(StudentProgressProviderInterface::class, MockStudentProgressProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
