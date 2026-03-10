<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('school')->group(function () {
    Route::get('dashboard/health', fn() => response()->json([
        'status' => 'ok',
        'service' => 'dashboard',
        'timestamp' => now()->toIso8601String(),
    ]));

    Route::middleware('mock.auth')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/students/{studentId}', [DashboardController::class, 'studentDrillDown']);
    });
});
