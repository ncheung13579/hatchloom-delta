<?php

/**
 * AppServiceProvider — Dependency injection bindings for the Dashboard Service.
 *
 * This is the central configuration point for the Dashboard Service's dependency
 * injection (DI) container. It wires together the Strategy pattern interfaces
 * with their concrete implementations and registers factory singletons.
 *
 * Why this file matters:
 *   When transitioning from mock data to real integrations, this is the ONLY
 *   file that needs to change to swap implementations. All service and controller
 *   code depends on interfaces, not concrete classes, so swapping a mock for a
 *   real implementation is a one-line change here.
 *
 * Current bindings (mock data):
 *   CredentialDataProviderInterface  -> MockCredentialDataProvider
 *   StudentProgressProviderInterface -> MockStudentProgressProvider
 *   LaunchPadDataProviderInterface   -> MockLaunchPadDataProvider
 *   DashboardWidgetFactory           -> singleton (stateless, reusable)
 *
 * Future bindings (real integrations):
 *   CredentialDataProviderInterface  -> CredentialDataProvider (queries Karl's tables)
 *   StudentProgressProviderInterface -> StudentProgressProvider (queries Course Service + activity logs)
 *   LaunchPadDataProviderInterface   -> HttpLaunchPadDataProvider (calls Quebec's LaunchPad API)
 *   DashboardWidgetFactory           -> singleton (no change needed)
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

        // Strategy binding: student progress metrics (mock — swap to real in AppServiceProvider)
        // Replace with real implementation when Team Papa's Course Service is integrated
        $this->app->bind(StudentProgressProviderInterface::class, MockStudentProgressProvider::class);

        // Strategy binding: LaunchPad venture data (mock — swap to real in AppServiceProvider)
        // Replace with real implementation when Team Quebec's LaunchPad Service is integrated
        $this->app->bind(LaunchPadDataProviderInterface::class, MockLaunchPadDataProvider::class);

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
