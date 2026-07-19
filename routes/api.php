<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::domain(config('domains.api'))->prefix('v1')->group(function (): void {
    Route::post('/auth/token', [AuthController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'ability:dashboard:read'])->group(function (): void {
        Route::delete('/auth/token', [AuthController::class, 'destroy'])
            ->middleware('throttle:10,1');
        Route::get('/dashboard', DashboardController::class)
            ->middleware('throttle:30,1');
    });
});
