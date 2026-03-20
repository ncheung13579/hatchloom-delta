<?php

declare(strict_types=1);

/**
 * AppServiceProvider — Dependency injection bindings for the Enrolment Service.
 *
 * This is where Laravel's service container is configured with interface-to-implementation
 * bindings. It is the key configuration point for the Strategy pattern used in this service.
 *
 * STRATEGY PATTERN BINDING:
 * The current binding is CredentialDataProviderInterface -> MockCredentialDataProvider.
 * This means whenever any class (currently EnrolmentService) asks for
 * CredentialDataProviderInterface via constructor injection, Laravel's container will
 * automatically provide an instance of MockCredentialDataProvider.
 *
 * HOW TO SWAP TO A REAL PROVIDER (when Karl's credential engine is ready):
 *   1. Create a new class (e.g., RealCredentialDataProvider) that implements
 *      CredentialDataProviderInterface with actual database queries or API calls
 *   2. Change the binding below from MockCredentialDataProvider to the new class
 *   3. No other code changes needed — EnrolmentService and EnrolmentController
 *      are unaffected because they depend on the interface, not the concrete class
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
use App\Services\MockCredentialDataProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Binds the CredentialDataProviderInterface to the mock implementation.
     * When Karl's credential engine is available, swap
     * MockCredentialDataProvider for the real implementation here.
     *
     * This single line is what makes the Strategy pattern work: it tells
     * Laravel's container "whenever someone asks for the interface, give
     * them this concrete class."
     */
    public function register(): void
    {
        $this->app->bind(CredentialDataProviderInterface::class, MockCredentialDataProvider::class);
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
