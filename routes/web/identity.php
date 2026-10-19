<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\LoginController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::middleware('guest:web')->group(function (): void {
    Route::get('/signin', [LoginController::class, 'create'])->name('login');
    Route::post('/signin', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::post('/signout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web')->name('logout');
