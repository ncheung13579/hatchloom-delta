<?php

/**
 * DashboardWidgetFactory — Factory Method implementation for dashboard widgets.
 *
 * Design pattern: Factory Method (SDD Section 6.4)
 *   This class is the "Creator" in the Factory Method pattern. It encapsulates
 *   the logic of deciding WHICH concrete DashboardWidget class to instantiate
 *   based on a type string. Callers (DashboardService) never reference concrete
 *   widget classes directly — they only work with the DashboardWidget interface.
 *
 * Why use a factory instead of direct instantiation?
 *   1. Open/Closed Principle: Adding a new widget type requires only a new class
 *      and a new entry in WIDGET_MAP. No changes to DashboardService or the controller.
 *   2. Centralized creation: All widget instantiation happens in one place, making
 *      it easy to audit which widgets exist and how they're constructed.
 *   3. Uniform constructor: All widgets receive the same $context array, and the
 *      factory enforces this convention.
 *
 * Registration:
 *   This factory is registered as a singleton in AppServiceProvider because it's
 *   stateless — there's no reason to create multiple instances. The DashboardService
 *   receives it via constructor injection.
 *
 * How to add a new widget:
 *   1. Create a class in App\Widgets that implements DashboardWidget
 *   2. Add 'type_string' => NewWidget::class to WIDGET_MAP below
 *   3. The new widget will automatically appear in the /widgets endpoint
 *
 * @see \App\Contracts\DashboardWidget  The product interface all widgets implement
 * @see \App\Services\DashboardService  The consumer that calls create() and buildWidget()
 */

declare(strict_types=1);

namespace App\Factories;

use App\Contracts\DashboardWidget;
use App\Widgets\CohortSummaryWidget;
use App\Widgets\EngagementChartWidget;
use App\Widgets\StudentTableWidget;
use InvalidArgumentException;

class DashboardWidgetFactory
{
    /**
     * Registry mapping type strings to widget class names.
     *
     * The keys are the type strings used in API URLs (e.g., /widgets/cohort_summary)
     * and in the JSON response's 'type' field. The values are fully-qualified
     * class names of DashboardWidget implementations.
     *
     * @var array<string, class-string<DashboardWidget>>
     */
    private const WIDGET_MAP = [
        'cohort_summary' => CohortSummaryWidget::class,
        'student_table' => StudentTableWidget::class,
        'engagement_chart' => EngagementChartWidget::class,
    ];

    /**
     * Create a dashboard widget instance for the given type.
     *
     * Looks up the type in WIDGET_MAP, then instantiates the corresponding
     * class with the shared context array. The context carries everything
     * widgets need (token, school_id, experiences, providers) so they can
     * fetch and format their data independently.
     *
     * @param string $type    One of the keys in WIDGET_MAP (e.g., 'cohort_summary')
     * @param array  $context Shared context from DashboardService::buildWidget()
     *
     * @throws InvalidArgumentException If the type is not registered in WIDGET_MAP.
     *         The error message lists all valid types to help with debugging.
     */
    public function create(string $type, array $context): DashboardWidget
    {
        if (!isset(self::WIDGET_MAP[$type])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown widget type "%s". Supported types: %s',
                    $type,
                    implode(', ', array_keys(self::WIDGET_MAP))
                )
            );
        }

        $widgetClass = self::WIDGET_MAP[$type];

        return new $widgetClass($context);
    }

    /**
     * Return the list of all registered widget types.
     *
     * Used by DashboardService::getAllWidgets() to iterate over every registered
     * type and build all widgets in a single response.
     *
     * @return string[]  e.g., ['cohort_summary', 'student_table', 'engagement_chart']
     */
    public function getAvailableTypes(): array
    {
        return array_keys(self::WIDGET_MAP);
    }
}
