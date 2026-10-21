<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

it('serves the component gallery in the testing environment', function () {
    $this->get('/_dev/ui')->assertOk()->assertInertia(fn (Assert $page) => $page->component('dev/UiGallery'));
});

it('registers no development or testing route when the application boots in production', function () {
    $result = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'TESTING_E2E' => 'true', 'TESTING_E2E_TOKEN' => 'x'])
        ->run(['php', 'artisan', 'route:list', '--json']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $uris = array_map(static fn (array $route): string => (string) $route['uri'], json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));

    expect($uris)->not->toBeEmpty();
    foreach ($uris as $uri) {
        expect($uri)->not->toStartWith('_dev')->not->toStartWith('_testing');
    }
});
