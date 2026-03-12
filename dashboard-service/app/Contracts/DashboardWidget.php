<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for dashboard widget components (Factory Method pattern).
 *
 * Each widget encapsulates the logic to gather and format a specific section
 * of the School Admin Dashboard (Screen 300). The DashboardWidgetFactory
 * instantiates the correct implementation based on a type string, and the
 * DashboardService calls getData() to retrieve the formatted payload.
 */
interface DashboardWidget
{
    /**
     * Gather and return the widget's data payload.
     *
     * @return array<string, mixed> Formatted data ready for JSON serialization
     */
    public function getData(): array;

    /**
     * Return the widget's type identifier.
     *
     * Used as the key in the dashboard response so the frontend knows which
     * component to render. Must match the type string passed to the factory.
     */
    public function getType(): string;
}
