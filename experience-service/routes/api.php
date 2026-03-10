<?php

declare(strict_types=1);

use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\ExperienceScreenController;
use Illuminate\Support\Facades\Route;

Route::prefix('school')->group(function () {
    Route::get('experiences/health', fn() => response()->json([
        'status' => 'ok',
        'service' => 'experience',
        'timestamp' => now()->toIso8601String(),
    ]));

    Route::middleware('mock.auth')->group(function () {
        // Screen 302 sub-resource routes must be registered before the
        // apiResource to prevent the {id} wildcard from swallowing them.
        Route::get('experiences/{id}/students/export', [ExperienceScreenController::class, 'exportStudents']);
        Route::get('experiences/{id}/students/{studentId}', [ExperienceScreenController::class, 'studentDetail']);
        Route::get('experiences/{id}/students', [ExperienceScreenController::class, 'students']);
        Route::get('experiences/{id}/contents', [ExperienceScreenController::class, 'contents']);
        Route::get('experiences/{id}/statistics', [ExperienceScreenController::class, 'statistics']);
        Route::apiResource('experiences', ExperienceController::class);
    });
});
