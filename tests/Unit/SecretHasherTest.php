<?php

declare(strict_types=1);

use App\Shared\Security\PepperNotConfigured;
use App\Shared\Security\SecretHasher;

function pepper(string $seed): string
{
    return base64_encode(str_pad($seed, 32, '*'));
}

it('hashes to 32 raw bytes, the same every time', function () {
    $hasher = new SecretHasher([1 => pepper('one')], 1);

    expect(strlen($hasher->hash('abcde-fghij')))->toBe(32)
        ->and($hasher->hash('abcde-fghij'))->toBe($hasher->hash('abcde-fghij'))
        ->and($hasher->hash('abcde-fghij'))->not->toBe($hasher->hash('abcde-fghik'));
});

it('gives different hashes under different peppers', function () {
    $hasher = new SecretHasher([1 => pepper('one'), 2 => pepper('two')], 1);

    expect($hasher->hash('secret', 1))->not->toBe($hasher->hash('secret', 2));
});

it('matches a secret stored under the previous pepper while one is rotating', function () {
    $before = new SecretHasher([1 => pepper('one')], 1);
    $stored = $before->hash('secret');

    $rotating = new SecretHasher([1 => pepper('one'), 2 => pepper('two')], 2, previousPepperId: 1);
    $rotated = new SecretHasher([1 => pepper('one'), 2 => pepper('two')], 2);

    expect($rotating->matches('secret', $stored))->toBeTrue()
        ->and($rotating->matches('other', $stored))->toBeFalse()
        ->and($rotating->candidates('secret'))->toHaveCount(2)
        ->and($rotating->candidates('secret')[0])->toBe($rotating->hash('secret', 2))
        ->and($rotated->matches('secret', $stored))->toBeFalse();
});

it('does not repeat the pepper when previous equals current', function () {
    $hasher = new SecretHasher([1 => pepper('one')], 1, previousPepperId: 1);

    expect($hasher->candidates('secret'))->toHaveCount(1);
});

it('refuses to hash without a usable pepper and never prints the value', function (?string $configured) {
    $hasher = new SecretHasher([1 => $configured], 1);

    expect(fn () => $hasher->hash('secret'))->toThrow(PepperNotConfigured::class);

    try {
        $hasher->hash('secret');
    } catch (PepperNotConfigured $e) {
        expect($e->getMessage())->toContain('BITFLAG_CREDENTIAL_PEPPER_1');

        if ($configured !== null && $configured !== '') {
            expect($e->getMessage())->not->toContain($configured);
        }
    }
})->with([
    'not set' => [null],
    'empty' => [''],
    'not base64' => ['!!not base64!!'],
    'wrong length' => [base64_encode('too short')],
]);
