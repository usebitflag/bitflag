<?php

declare(strict_types=1);

use App\Modules\Environments\Domain\EnvironmentTemplate;
use App\Modules\Projects\Domain\ProjectKeyRules;

it('accepts a lower-case key of 2 to 40 characters that starts with a letter', function (string $key) {
    expect(ProjectKeyRules::problem($key))->toBeNull();
})->with(['storefront', 'checkout-api', 'a1', 'ab', str_repeat('a', 40)]);

it('refuses a key that does not fit', function (string $key) {
    expect(ProjectKeyRules::problem($key))->toBe(ProjectKeyRules::BAD);
})->with(['', 'a', '1shop', '-shop', 'Shop', 'my shop', 'shop_api', str_repeat('a', 41)]);

it('refuses the words the organization pages use', function (string $key) {
    expect(ProjectKeyRules::problem($key))->toBe(ProjectKeyRules::RESERVED_WORD);
})->with(ProjectKeyRules::RESERVED);

it('suggests a key from a name', function (string $name, string $key) {
    expect(ProjectKeyRules::suggest($name))->toBe($key);
})->with([['Storefront', 'storefront'], ['Checkout API', 'checkout-api'], ['  42 Shop!! ', 'shop'], ['!!', '']]);

it('starts only production and prod protected', function () {
    expect(EnvironmentTemplate::startsProtected('production'))->toBeTrue()
        ->and(EnvironmentTemplate::startsProtected('prod'))->toBeTrue()
        ->and(EnvironmentTemplate::startsProtected('staging'))->toBeFalse()
        ->and(EnvironmentTemplate::startsProtected('production-eu'))->toBeFalse()
        ->and(EnvironmentTemplate::startsProtected('preprod'))->toBeFalse();
});

it('has the three template environments in order', function () {
    expect(array_column(EnvironmentTemplate::default(), 'key'))->toBe(['development', 'staging', 'production'])
        ->and(EnvironmentTemplate::colorFor('qa')->value)->toBe('ink');
});
