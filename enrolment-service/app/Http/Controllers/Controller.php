<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Abstract base controller for all Enrolment Service controllers.
 *
 * Provides shared error-response helpers used by CohortController and
 * EnrolmentController. Promoted from private methods to eliminate
 * duplicated error formatting across controllers.
 *
 * @see \App\Http\Controllers\CohortController     Cohort CRUD and state transitions
 * @see \App\Http\Controllers\EnrolmentController  Student enrolment operations
 */
abstract class Controller
{
    /**
     * Build a standardized JSON error response.
     */
    protected function errorResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'error' => true,
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    /**
     * Build a 404 not-found error response.
     */
    protected function notFoundResponse(string $message): JsonResponse
    {
        return $this->errorResponse($message, 'NOT_FOUND', 404);
    }
}
