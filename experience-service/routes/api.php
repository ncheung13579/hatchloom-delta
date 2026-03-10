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
        Route::apiResource('experiences', ExperienceController::class);
        Route::get('experiences/{id}/students', [ExperienceScreenController::class, 'students']);
        Route::get('experiences/{id}/contents', [ExperienceScreenController::class, 'contents']);
        Route::get('experiences/{id}/statistics', [ExperienceScreenController::class, 'statistics']);
    });
});
