<?php

/**
 * Controller — Abstract base controller for all Dashboard Service HTTP controllers.
 *
 * This is Laravel's default base controller. All concrete controllers (e.g.,
 * DashboardController) extend this class. It currently contains no shared logic
 * because the Dashboard Service has a single controller that delegates all work
 * to DashboardService.
 *
 * If cross-cutting controller concerns emerge (e.g., shared response formatting,
 * pagination helpers), add them here so every controller inherits them.
 *
 * @see \App\Http\Controllers\DashboardController  The only concrete controller currently
 */

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
