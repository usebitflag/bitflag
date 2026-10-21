<?php

declare(strict_types=1);

const MODULES = [
    'Identity', 'Organizations', 'Projects', 'Environments', 'Flags', 'Targeting', 'Publishing', 'Credentials',
    'Experiments', 'Analytics', 'Integrations', 'Audit', 'Billing', 'Notifications', 'Support',
];

it('registers a service provider for every module', function (string $module) {
    $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue()
        ->and(app()->getProviders($provider))->not->toBeEmpty();
})->with(MODULES);

it('has no module folder without a registered provider', function () {
    $folders = array_map('basename', glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: []);
    sort($folders);
    $expected = MODULES;
    sort($expected);

    expect($folders)->toBe($expected);
});
