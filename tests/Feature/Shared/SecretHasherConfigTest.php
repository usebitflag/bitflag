<?php

declare(strict_types=1);

use App\Shared\Security\SecretHasher;

it('is built from the configured peppers', function () {
    $hasher = app(SecretHasher::class);

    expect(strlen($hasher->hash('secret')))->toBe(32)
        ->and($hasher->currentPepperId())->toBe(1);
});

it('reads the previous pepper while one is rotating', function () {
    config()->set('bitflag.credentials.current_pepper_id', 2);
    config()->set('bitflag.credentials.previous_pepper_id', 1);
    app()->forgetInstance(SecretHasher::class);

    expect(app(SecretHasher::class)->candidates('secret'))->toHaveCount(2);
});
