<?php

declare(strict_types=1);

namespace App\Factories;

use App\Contracts\DashboardWidget;
use App\Widgets\CohortSummaryWidget;
use App\Widgets\EngagementChartWidget;
use App\Widgets\StudentTableWidget;
use InvalidArgumentException;

/**
 * Factory Method implementation for dashboard widgets.
 *
 * Maps widget type strings to concrete DashboardWidget implementations.
 * The DashboardService calls create() with a type and a context array,
 * and receives a fully constructed widget whose getData() method returns
 * the formatted payload for that section of the dashboard.
 *
 * Design pattern: Factory Method — the factory encapsulates object-creation
 * logic so callers do not need to know which concrete class to instantiate.
 * Adding a new widget type requires only a new class and a new entry in the
 * WIDGET_MAP constant.
 */
class DashboardWidgetFactory
{
    /**
     * Registry mapping type strings to widget class names.
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
     * @param string $type    One of the keys in WIDGET_MAP
     * @param array  $context Shared context (token, school_id, etc.) passed to the widget constructor
     *
     * @throws InvalidArgumentException If the type is not registered
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
     * @return string[]
     */
    public function getAvailableTypes(): array
    {
        return array_keys(self::WIDGET_MAP);
    }
}
