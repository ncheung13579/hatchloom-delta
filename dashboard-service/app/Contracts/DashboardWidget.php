<?php

/**
 * DashboardWidget — Contract for pluggable dashboard widget components.
 *
 * Design pattern: Factory Method (product interface)
 *   This interface is the "Product" in the Factory Method pattern. Each
 *   concrete widget (CohortSummaryWidget, StudentTableWidget, EngagementChartWidget)
 *   implements this interface. The DashboardWidgetFactory is the "Creator" that
 *   instantiates the correct concrete product based on a type string.
 *
 * How widgets work:
 *   1. DashboardService::buildWidget() assembles a context array (token, school_id, etc.)
 *   2. DashboardWidgetFactory::create($type, $context) instantiates the right widget
 *   3. The widget's getData() method fetches and formats its section of the dashboard
 *   4. The controller serializes the result to JSON for the frontend
 *
 * Adding a new widget:
 *   1. Create a new class in App\Widgets that implements DashboardWidget
 *   2. Add a type string -> class mapping in DashboardWidgetFactory::WIDGET_MAP
 *   That's it — no changes needed in the controller or service layer.
 *
 * Current implementations:
 *   - CohortSummaryWidget  ('cohort_summary')  — Cohort counts and enrolment stats
 *   - StudentTableWidget   ('student_table')    — Student roster with enrolment status
 *   - EngagementChartWidget ('engagement_chart') — Engagement metrics for charting
 *
 * @see \App\Factories\DashboardWidgetFactory  The factory that creates widgets
 * @see \App\Services\DashboardService         The service that orchestrates widget building
 */

declare(strict_types=1);

namespace App\Contracts;

interface DashboardWidget
{
    /**
     * Gather and return the widget's data payload.
     *
     * Each widget is responsible for fetching its own data (potentially via
     * HTTP calls to downstream services) and returning a structured array
     * ready for JSON serialization. If a downstream service is unavailable,
     * widgets should degrade gracefully (return partial data with warnings)
     * rather than throwing exceptions.
     *
     * @return array<string, mixed> Formatted data ready for JSON serialization
     */
    public function getData(): array;

    /**
     * Return the widget's type identifier.
     *
     * This string is used as the key in the dashboard API response so the
     * frontend knows which UI component to render for this data. The type
     * must match the key used in DashboardWidgetFactory::WIDGET_MAP.
     *
     * @return string  Widget type (e.g., 'cohort_summary', 'student_table')
     */
    public function getType(): string;
}
