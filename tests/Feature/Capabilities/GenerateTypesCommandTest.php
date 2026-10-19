<?php

declare(strict_types=1);

use App\Shared\Capabilities\Capability;
use App\Shared\Ids\IdPrefix;
use Illuminate\Support\Facades\Artisan;

it('keeps the generated TypeScript types current', function () {
    expect(Artisan::call('bitflag:types', ['--check' => true]))->toBe(0);
});

it('lists every capability and ID prefix in the generated files', function () {
    $capabilities = (string) file_get_contents(resource_path('js/types/capabilities.ts'));
    $ids = (string) file_get_contents(resource_path('js/types/ids.ts'));

    foreach (Capability::cases() as $capability) {
        expect($capabilities)->toContain("'{$capability->value}'");
    }

    foreach (IdPrefix::cases() as $prefix) {
        expect($ids)->toContain("'{$prefix->value}'");
    }
});
