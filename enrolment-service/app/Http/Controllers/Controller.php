<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Abstract base controller for all Enrolment Service controllers.
 *
 * Laravel 11 ships this as an empty abstract class. Concrete controllers
 * (CohortController, EnrolmentController) extend it so that shared helper
 * methods (e.g., errorResponse, notFoundResponse) can be promoted here if
 * they are needed across multiple controllers in the future.
 *
 * @see \App\Http\Controllers\CohortController     Cohort CRUD and state transitions
 * @see \App\Http\Controllers\EnrolmentController  Student enrolment operations
 */
abstract class Controller
{
    //
}
