<?php

declare(strict_types=1);

it('registers a service provider for every module', function (string $module) {
    $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

    expect(class_exists($provider))->toBeTrue()
        ->and(app()->getProviders($provider))->not->toBeEmpty();
})->with(bitflag_modules());

it('has no module folder without a registered provider', function () {
    $folders = array_map('basename', glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: []);
    sort($folders);
    $expected = bitflag_modules();
    sort($expected);

    expect($folders)->toBe($expected);
});
