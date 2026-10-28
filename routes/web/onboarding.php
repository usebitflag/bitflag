<?php

declare(strict_types=1);

use App\Modules\Organizations\Http\Controllers\Web\OnboardingController;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use Illuminate\Support\Facades\Route;

// The sign-up wizard after the account. Each step sits behind EnsureOnboardingStep, which sends a person to the step the
// facts say they are at.
$slug = app(OrganizationSlugs::class)->routePattern();

Route::middleware('auth:web')->prefix('onboarding')->group(function () use ($slug): void {
    Route::get('/', [OnboardingController::class, 'resume'])->name('onboarding');
    Route::get('/slug-availability', [OnboardingController::class, 'slugAvailability'])->middleware('throttle:onboarding-slug')->name('onboarding.slug');

    Route::middleware('onboarding.step:organization')->group(function (): void {
        Route::get('/organization', [OnboardingController::class, 'organization'])->name('onboarding.organization');
        Route::post('/organization', [OnboardingController::class, 'storeOrganization'])->name('onboarding.organization.store');
    });

    Route::middleware('onboarding.step:project')->group(function () use ($slug): void {
        Route::get('/{organization}/project', [OnboardingController::class, 'project'])->where('organization', $slug)->name('onboarding.project');
        Route::post('/{organization}/project', [OnboardingController::class, 'storeProject'])->where('organization', $slug)->name('onboarding.project.store');
    });

    Route::middleware('onboarding.step:team')->group(function () use ($slug): void {
        Route::get('/{organization}/team', [OnboardingController::class, 'team'])->where('organization', $slug)->name('onboarding.team');
        Route::post('/{organization}/team', [OnboardingController::class, 'inviteTeam'])->where('organization', $slug)->name('onboarding.team.invite');
        Route::post('/{organization}/team/skip', [OnboardingController::class, 'skipTeam'])->where('organization', $slug)->name('onboarding.team.skip');
    });

    Route::middleware('onboarding.step:ready')->group(function () use ($slug): void {
        Route::get('/{organization}/ready', [OnboardingController::class, 'ready'])->where('organization', $slug)->name('onboarding.ready');
        Route::post('/{organization}/ready', [OnboardingController::class, 'finish'])->where('organization', $slug)->name('onboarding.ready.finish');
    });
});
