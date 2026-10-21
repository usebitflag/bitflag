<?php

declare(strict_types=1);

use App\Http\Controllers\Testing\ClockController;
use App\Http\Controllers\Testing\FailController;
use App\Http\Controllers\Testing\LoginController;
use App\Http\Controllers\Testing\ResetController;
use App\Http\Middleware\RequireTestingToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// End-to-end test routes. Loaded only when TESTING_E2E is true in the local or testing environment, and each request
// must carry the testing token. They are called by the Cypress harness, not by a browser form, so CSRF does not apply.
Route::prefix('_testing')
    ->middleware(RequireTestingToken::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->group(function (): void {
        Route::post('/reset', ResetController::class)->name('testing.reset');
        Route::post('/login', LoginController::class)->name('testing.login');
        Route::post('/clock', ClockController::class)->name('testing.clock');
        Route::match(['get', 'post'], '/fail', FailController::class)->name('testing.fail');
    });
