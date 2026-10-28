<?php

declare(strict_types=1);

use App\Modules\Credentials\Http\Controllers\Api\V1\MeController;
use App\Modules\Environments\Http\Controllers\Api\V1\EnvironmentController;
use App\Modules\Projects\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Support\Facades\Route;

// Management API, served under /api/v1. Every route authenticates a management key or a personal access token.
Route::middleware('auth.token')->group(function (): void {
    Route::get('/me', [MeController::class, 'show']);
    Route::delete('/me/token', [MeController::class, 'destroy']);

    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/environments', [EnvironmentController::class, 'index']);
    Route::get('/projects/{project}/environments/{environment}', [EnvironmentController::class, 'show']);
});
