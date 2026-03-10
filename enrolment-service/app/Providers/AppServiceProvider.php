<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CredentialDataProviderInterface;
use App\Services\MockCredentialDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Binds the CredentialDataProviderInterface to the mock implementation
     * for D1. When Karl's credential engine is available, swap
     * MockCredentialDataProvider for the real implementation here.
     */
    public function register(): void
    {
        $this->app->bind(CredentialDataProviderInterface::class, MockCredentialDataProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
