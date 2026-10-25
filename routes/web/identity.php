<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\CheckEmailController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\PasswordCheckController;
use App\Modules\Identity\Http\Controllers\Web\PasswordController;
use App\Modules\Identity\Http\Controllers\Web\PasswordResetController;
use App\Modules\Identity\Http\Controllers\Web\SecurityController;
use App\Modules\Identity\Http\Controllers\Web\SignUpController;
use App\Modules\Identity\Http\Controllers\Web\TwoFactorChallengeController;
use App\Modules\Identity\Http\Controllers\Web\TwoFactorSettingsController;
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

Route::middleware('guest:web')->group(function (): void {
    Route::get('/forgot-password', [PasswordResetController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/forgot-password/sent', [PasswordResetController::class, 'sent'])->name('password.sent');
    Route::post('/forgot-password/resend', [PasswordResetController::class, 'resend'])->middleware('throttle:password-reset')->name('password.resend');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

// The second step of signing in belongs to a browser that is not signed in yet: it holds only a pending sign-in.
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:login')->name('two-factor.login');

Route::middleware('auth:web')->group(function (): void {
    Route::get('/account/security', [SecurityController::class, 'show'])->name('account.security');
    Route::post('/account/two-factor', [TwoFactorSettingsController::class, 'start'])->middleware('throttle:10,1')->name('account.two-factor.start');
    Route::post('/account/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])->middleware('throttle:10,1')->name('account.two-factor.confirm');
    Route::delete('/account/two-factor', [TwoFactorSettingsController::class, 'disable'])->middleware('throttle:10,1')->name('account.two-factor.disable');
    Route::post('/account/recovery-codes', [TwoFactorSettingsController::class, 'regenerate'])->middleware('throttle:10,1')->name('account.recovery-codes');
    Route::put('/account/password', [PasswordController::class, 'update'])->middleware('throttle:10,1')->name('account.password.update');
    Route::post('/signup/change-email', [CheckEmailController::class, 'change'])->middleware('throttle:signup')->name('signup.change-email');
    Route::post('/email/verification-notification', [CheckEmailController::class, 'resend'])->middleware('throttle:verification')->name('verification.send');
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->middleware('signed')->name('verification.verify');
});

Route::post('/signout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web')->name('logout');
