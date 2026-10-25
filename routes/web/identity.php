<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\CheckEmailController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\PasswordCheckController;
use App\Modules\Identity\Http\Controllers\Web\SignUpController;
use App\Modules\Identity\Http\Controllers\Web\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::middleware('guest:web')->group(function (): void {
    Route::redirect('/login', '/signin');
    Route::get('/signin', [LoginController::class, 'create'])->name('login');
    Route::post('/signin', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('guest:web')->group(function (): void {
    Route::get('/signup', [SignUpController::class, 'create'])->name('signup');
    Route::post('/signup', [SignUpController::class, 'store'])->middleware('throttle:signup')->name('signup.store');
});

// Both a new and an existing address end here, so the page is open to a guest as well as to the person just signed in.
Route::get('/signup/check-email', [CheckEmailController::class, 'show'])->name('signup.check-email');
Route::post('/password/check', PasswordCheckController::class)->middleware('throttle:password-check')->name('password.check');

Route::middleware('auth:web')->group(function (): void {
    Route::post('/signup/change-email', [CheckEmailController::class, 'change'])->middleware('throttle:signup')->name('signup.change-email');
    Route::post('/email/verification-notification', [CheckEmailController::class, 'resend'])->middleware('throttle:verification')->name('verification.send');
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->middleware('signed')->name('verification.verify');
});

Route::post('/signout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web')->name('logout');
