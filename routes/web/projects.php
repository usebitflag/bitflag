<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\NoAccessController;
use App\Modules\Identity\Http\Controllers\Web\RememberScopeController;
use App\Modules\Projects\Http\Controllers\Web\HomeController;
use App\Modules\Projects\Http\Controllers\Web\OverviewController;
use Illuminate\Support\Facades\Route;

// Portal URLs lead with the organization slug: /{organization}/{project}/{environment}/{page}. Slugs start with a letter,
// so they can never collide with the underscore-prefixed development and testing paths. Words that are application
// paths are reserved and can never be organization slugs.
$slug = '(?!(?:up|horizon|signin|signout|login|logout|scope|no-access)$)[a-z][a-z0-9-]{1,38}';
$key = '[a-z0-9][a-z0-9-]{0,39}';

Route::middleware('auth:web')->group(function () use ($slug, $key): void {
    Route::get('/', [HomeController::class, 'root'])->name('home');
    Route::get('/no-access', [NoAccessController::class, 'show'])->name('account.no-access');
    Route::post('/scope/remember', [RememberScopeController::class, 'store'])->name('scope.remember');

    Route::middleware('portal')->group(function () use ($slug, $key): void {
        Route::get('/{organization}', [HomeController::class, 'organization'])->where('organization', $slug)->name('organization.home');

        // Placeholder for the "Organization settings" link until the organization pages exist.
        Route::get('/{organization}/settings', fn () => redirect()->route('organization.home', ['organization' => request()->route('organization')]))
            ->where('organization', $slug)->name('organization.settings');

        Route::get('/{organization}/{project}', [HomeController::class, 'project'])
            ->where(['organization' => $slug, 'project' => $key])->name('project.home');

        Route::get('/{organization}/{project}/{environment}/overview', [OverviewController::class, 'show'])
            ->where(['organization' => $slug, 'project' => $key, 'environment' => $key])->name('project.overview');
    });
});
