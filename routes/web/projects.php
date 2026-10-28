<?php

declare(strict_types=1);

use App\Modules\Credentials\Http\Controllers\Web\AccountTokensController;
use App\Modules\Credentials\Http\Controllers\Web\EnvironmentKeysController;
use App\Modules\Credentials\Http\Controllers\Web\ProjectTokensController;
use App\Modules\Environments\Http\Controllers\Web\EnvironmentSettingsController;
use App\Modules\Identity\Http\Controllers\Web\NoAccessController;
use App\Modules\Identity\Http\Controllers\Web\RememberScopeController;
use App\Modules\Organizations\Http\Controllers\Web\ChooseOrganizationController;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use App\Modules\Projects\Http\Controllers\Web\HomeController;
use App\Modules\Projects\Http\Controllers\Web\OverviewController;
use App\Modules\Projects\Http\Controllers\Web\ProjectSettingsController;
use Illuminate\Support\Facades\Route;

// Portal URLs lead with the organization slug: /{organization}/{project}/{environment}/{page}. Slugs start with a letter,
// so they can never collide with the underscore-prefixed development and testing paths. Words that are application
// paths are reserved and can never be organization slugs (see the organization slug rules).
$slug = app(OrganizationSlugs::class)->routePattern();
$key = '[a-z0-9][a-z0-9-]{0,39}';

Route::middleware('auth:web')->group(function () use ($slug, $key): void {
    Route::get('/', [HomeController::class, 'root'])->name('home');
    Route::get('/no-access', [NoAccessController::class, 'show'])->name('account.no-access');
    Route::get('/organizations/choose', [ChooseOrganizationController::class, 'show'])->name('organizations.choose');
    Route::post('/organizations/choose', [ChooseOrganizationController::class, 'store'])->name('organizations.choose.store');
    Route::get('/account/tokens', [AccountTokensController::class, 'index'])->name('account.tokens');
    Route::post('/account/tokens/{credential}/revoke', [AccountTokensController::class, 'revoke'])->where('credential', 'key_[0-9a-z]{26}')->name('account.tokens.revoke');
    Route::post('/scope/remember', [RememberScopeController::class, 'store'])->name('scope.remember');

    Route::middleware(['portal', 'org.mfa'])->group(function () use ($slug, $key): void {
        Route::get('/{organization}', [HomeController::class, 'organization'])->where('organization', $slug)->name('organization.home');

        Route::get('/{organization}/{project}', [HomeController::class, 'project'])
            ->where(['organization' => $slug, 'project' => $key])->name('project.home');

        Route::get('/{organization}/{project}/{environment}/overview', [OverviewController::class, 'show'])
            ->where(['organization' => $slug, 'project' => $key, 'environment' => $key])->name('project.overview');

        // Project settings. The Members tab lives in routes/web/organizations.php.
        Route::prefix('/{organization}/{project}/{environment}/settings')->where(['organization' => $slug, 'project' => $key, 'environment' => $key])->group(function () use ($key): void {
            Route::get('/', [ProjectSettingsController::class, 'index'])->name('project.settings');
            Route::get('/general', [ProjectSettingsController::class, 'general'])->name('project.settings.general');
            Route::put('/general', [ProjectSettingsController::class, 'updateGeneral'])->name('project.settings.general.update');

            Route::get('/tokens', [ProjectTokensController::class, 'index'])->name('project.settings.tokens');
            Route::post('/tokens', [ProjectTokensController::class, 'store'])->middleware('throttle:10,1')->name('project.settings.tokens.store');
            Route::post('/tokens/{credential}/revoke', [ProjectTokensController::class, 'revoke'])->where('credential', 'key_[0-9a-z]{26}')->name('project.settings.tokens.revoke');

            Route::get('/environments', [EnvironmentSettingsController::class, 'index'])->name('project.settings.environments');
            Route::post('/environments', [EnvironmentSettingsController::class, 'store'])->name('project.settings.environments.store');
            Route::put('/environments/order', [EnvironmentSettingsController::class, 'reorder'])->name('project.settings.environments.order');
            Route::post('/environments/{target}/keys', [EnvironmentKeysController::class, 'store'])->where(['target' => $key])->middleware('throttle:30,1')->name('project.settings.environments.keys.store');
            Route::post('/environments/{target}/keys/{credential}/rotate', [EnvironmentKeysController::class, 'rotate'])->where(['target' => $key, 'credential' => 'key_[0-9a-z]{26}'])->middleware('throttle:30,1')->name('project.settings.environments.keys.rotate');
            Route::post('/environments/{target}/keys/{credential}/revoke', [EnvironmentKeysController::class, 'revoke'])->where(['target' => $key, 'credential' => 'key_[0-9a-z]{26}'])->name('project.settings.environments.keys.revoke');
            Route::post('/environments/{target}/archive', [EnvironmentSettingsController::class, 'archive'])->where(['target' => $key])->name('project.settings.environments.archive');
            Route::delete('/environments/{target}', [EnvironmentSettingsController::class, 'destroy'])->where(['target' => $key])->name('project.settings.environments.destroy');
            Route::put('/environments/{target}/settings', [EnvironmentSettingsController::class, 'update'])
                ->where(['target' => $key])->name('project.settings.environments.update');
            Route::get('/environments/{target}/{tab?}', [EnvironmentSettingsController::class, 'show'])
                ->where(['target' => $key, 'tab' => 'overview|keys|delivery|history|settings'])->name('project.settings.environments.show');
        });
    });
});
