<?php

declare(strict_types=1);

/**
 * AppServiceProvider — Dependency injection bindings for the Enrolment Service.
 *
 * This is where Laravel's service container is configured with interface-to-implementation
 * bindings. It is the key configuration point for the Strategy pattern used in this service.
 *
 * STRATEGY PATTERN BINDING:
 *   CredentialDataProviderInterface:
 *     'http' -> HttpCredentialDataProvider  (calls Karl's Credential Engine)
 *     'mock' -> MockCredentialDataProvider  (returns sample credential data)
 *
 * The AUTH_MODE environment variable controls which implementation is injected.
 * EnrolmentService and EnrolmentController are unaffected because they depend
 * on the interface, not the concrete class.
 *
 * WHY bind() AND NOT singleton():
 * bind() creates a new instance each time the interface is resolved. Since
 * MockCredentialDataProvider is stateless (it just returns hardcoded data),
 * there is no performance benefit to caching a single instance. If the real
 * provider holds a database connection, singleton() might be more appropriate.
 *
 * @see \App\Contracts\CredentialDataProviderInterface  The Strategy pattern interface
 * @see \App\Services\MockCredentialDataProvider        The current mock implementation
 * @see \App\Services\EnrolmentService                  The consumer that depends on the interface
 */

namespace App\Providers;

use App\Contracts\CredentialDataProviderInterface;
use App\Services\HttpCredentialDataProvider;
use App\Services\MockCredentialDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Binds the CredentialDataProviderInterface to either the HTTP or mock
     * implementation based on the AUTH_MODE env var. This is the Strategy
     * pattern in action: the container decides which concrete class to inject.
     */
    public function register(): void
    {
        $this->app->bind(
            CredentialDataProviderInterface::class,
            env('AUTH_MODE', 'http') === 'http'
                ? HttpCredentialDataProvider::class
                : MockCredentialDataProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     *
     * Currently empty. When real services are integrated, this could register
     * event listeners, middleware aliases, or other boot-time configuration.
     */
    public function boot(): void
    {
        //
    }
}
