<?php

/**
 * Controller — Abstract base controller for all Experience Service HTTP controllers.
 *
 * This is Laravel's default base controller. Concrete controllers (ExperienceController,
 * ExperienceScreenController) extend this class. It is intentionally empty because the
 * Experience Service does not use controller-level traits like AuthorizesRequests or
 * ValidatesRequests -- validation is done inline via $request->validate() in each
 * controller method, and authorization is handled by MockAuthMiddleware before
 * the request reaches any controller.
 *
 * If shared controller behavior is needed in the future (e.g., a standardized
 * error response helper), add it here so all controllers inherit it.
 *
 * @see \App\Http\Controllers\ExperienceController        Screen 301 CRUD endpoints
 * @see \App\Http\Controllers\ExperienceScreenController   Screen 302 sub-resource endpoints
 */

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
