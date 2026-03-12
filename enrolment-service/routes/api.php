<?php

declare(strict_types=1);

use App\Http\Controllers\CohortController;
use App\Http\Controllers\EnrolmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('school')->group(function () {
    Route::get('enrolments/health', fn() => response()->json([
        'status' => 'ok',
        'service' => 'enrolment',
        'timestamp' => now()->toIso8601String(),
    ]));

    Route::middleware('mock.auth')->group(function () {
        Route::apiResource('cohorts', CohortController::class)->except(['destroy']);
        Route::patch('cohorts/{id}/activate', [CohortController::class, 'activate']);
        Route::patch('cohorts/{id}/complete', [CohortController::class, 'complete']);
        Route::post('cohorts/{cohortId}/enrolments', [EnrolmentController::class, 'enrol']);
        Route::delete('cohorts/{cohortId}/enrolments/{studentId}', [EnrolmentController::class, 'remove']);
        Route::get('enrolments', [EnrolmentController::class, 'index']);
        Route::get('enrolments/statistics', [EnrolmentController::class, 'statistics']);
        Route::get('enrolments/students/{studentId}', [EnrolmentController::class, 'studentDetail']);
        Route::get('enrolments/export', [EnrolmentController::class, 'export']);
    });
});
