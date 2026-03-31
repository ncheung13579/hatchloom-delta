<?php

/**
 * AppServiceProvider — Dependency injection bindings for the Dashboard Service.
 *
 * This is where the Strategy pattern is wired. Each external data dependency is
 * defined as an interface, and this provider tells Laravel's container which
 * concrete class to inject. Controllers and services never know which
 * implementation they receive — they depend on the interface only.
 *
 * AUTH_MODE toggle (Strategy pattern):
 *   The AUTH_MODE environment variable controls whether providers call real
 *   external APIs or return hardcoded mock data. This toggle applies to:
 *
 *   StudentProgressProviderInterface:
 *     'http' -> HttpStudentProgressProvider  (calls Team Papa's Course Service)
 *     'mock' -> MockStudentProgressProvider  (returns sample metrics)
 *
 *   LaunchPadDataProviderInterface:
 *     'http' -> HttpLaunchPadDataProvider    (calls Team Quebec's User Service)
 *     'mock' -> MockLaunchPadDataProvider    (returns sample venture data)
 *
 * Not yet toggled (pending external team):
 *   CredentialDataProviderInterface -> MockCredentialDataProvider (always mock)
 *     Karl's credential engine is not yet available. When it is, add an
 *     HttpCredentialDataProvider and wire it with the same AUTH_MODE toggle.
 *
 * Other bindings:
 *   DashboardWidgetFactory -> singleton (Factory pattern, stateless)
 *
 * @see \App\Contracts\CredentialDataProviderInterface   Strategy interface for credentials
 * @see \App\Contracts\StudentProgressProviderInterface  Strategy interface for progress metrics
 * @see \App\Contracts\LaunchPadDataProviderInterface    Strategy interface for LaunchPad ventures
 * @see \App\Factories\DashboardWidgetFactory            Factory for widget instantiation
 */

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CredentialDataProviderInterface;
use App\Contracts\LaunchPadDataProviderInterface;
use App\Contracts\StudentProgressProviderInterface;
use App\Factories\DashboardWidgetFactory;
use App\Services\HttpLaunchPadDataProvider;
use App\Services\HttpStudentProgressProvider;
use App\Services\MockCredentialDataProvider;
use App\Services\MockLaunchPadDataProvider;
use App\Services\MockStudentProgressProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services in the DI container.
     *
     * The bind() calls register interface-to-implementation mappings. When any
     * class (e.g., DashboardService) type-hints one of these interfaces in its
     * constructor, Laravel automatically resolves it to the bound implementation.
     *
     * bind() vs singleton():
     *   - bind(): Creates a NEW instance each time the interface is resolved.
     *     Used for providers because they may hold request-specific state in the future.
     *   - singleton(): Creates ONE instance and reuses it for all resolutions.
     *     Used for DashboardWidgetFactory because it's stateless (just a type map).
     */
    public function register(): void
    {
        // Strategy binding: credential data (mock — swap to real in AppServiceProvider)
        // Replace with real implementation when Karl's credential engine is ready
        $this->app->bind(CredentialDataProviderInterface::class, MockCredentialDataProvider::class);

        // Strategy binding: student progress metrics
        // Toggle via AUTH_MODE env var: 'http' uses Papa's real API, 'mock' uses sample data
        $this->app->bind(
            StudentProgressProviderInterface::class,
            env('AUTH_MODE', 'http') === 'http'
                ? HttpStudentProgressProvider::class
                : MockStudentProgressProvider::class
        );

        // Strategy binding: LaunchPad venture data
        // Toggle via AUTH_MODE env var: 'http' uses Quebec's real API, 'mock' uses sample data
        $this->app->bind(
            LaunchPadDataProviderInterface::class,
            env('AUTH_MODE', 'http') === 'http'
                ? HttpLaunchPadDataProvider::class
                : MockLaunchPadDataProvider::class
        );

        // Widget factory: singleton because it's stateless — the WIDGET_MAP constant
        // never changes, so there's no reason to create multiple instances
        $this->app->singleton(DashboardWidgetFactory::class);
    }

    /**
     * Bootstrap any application services.
     *
     * Currently empty. This is where you'd register event listeners, observers,
     * global scopes, or other boot-time configuration when real services are integrated.
     */
    public function boot(): void
    {
        //
    }
}
