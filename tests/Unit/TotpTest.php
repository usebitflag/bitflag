<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Base32;
use App\Modules\Identity\Domain\RecoveryCodes;
use App\Modules\Identity\Domain\Totp;

// RFC 6238 appendix B uses the ASCII secret "12345678901234567890" and an eight-digit code; six digits are its last six.
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('encodes and decodes base32', function () {
    expect(Base32::encode('12345678901234567890'))->toBe(RFC_SECRET)
        ->and(Base32::decode(RFC_SECRET))->toBe('12345678901234567890')
        ->and(Base32::decode(strtolower(RFC_SECRET)))->toBe('12345678901234567890');
});

it('refuses a character outside the base32 alphabet', function () {
    Base32::decode('ABC1');
})->throws(InvalidArgumentException::class);

it('matches the RFC 6238 test vectors', function (int $time, string $code) {
    expect(Totp::code(RFC_SECRET, Totp::timestep($time)))->toBe($code);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('accepts a code one step either side of now, and no further', function () {
    $now = 1_700_000_000;
    $step = Totp::timestep($now);

    expect(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step), $now))->toBe($step)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step - 1), $now))->toBe($step - 1)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step + 1), $now))->toBe($step + 1)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step - 2), $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step + 2), $now))->toBeNull();
});

it('rejects a step that is not after the last one used', function () {
    $now = 1_700_000_000;
    $step = Totp::timestep($now);
    $code = Totp::code(RFC_SECRET, $step);

    expect(Totp::verify(RFC_SECRET, $code, $now, $step))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, $code, $now, $step + 1))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, $code, $now, $step - 1))->toBe($step);
});

it('rejects codes of the wrong shape and ignores spaces', function () {
    $now = 1_700_000_000;
    $code = Totp::code(RFC_SECRET, Totp::timestep($now));

    expect(Totp::verify(RFC_SECRET, substr($code, 0, 5), $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, $code.'0', $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, 'abcdef', $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, '', $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, substr($code, 0, 3).' '.substr($code, 3), $now))->not->toBeNull();
});

it('makes secrets that authenticator apps accept', function () {
    $secret = Totp::generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and(Totp::generateSecret())->not->toBe($secret)
        ->and(Totp::provisioningUri($secret, 'maya@acme.dev'))
        ->toBe("otpauth://totp/BitFlag:maya%40acme.dev?secret={$secret}&issuer=BitFlag&algorithm=SHA1&digits=6&period=30");
});

it('makes ten distinct recovery codes in the xxxxx-xxxxx form', function () {
    $codes = RecoveryCodes::generate();

    expect($codes)->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10)
        ->and($codes)->each->toMatch('/^[a-z2-7]{5}-[a-z2-7]{5}$/');
});

it('treats a recovery code the same with or without the dash, in any case', function () {
    expect(RecoveryCodes::normalize(' ABCDE-fghij '))->toBe('abcdefghij')
        ->and(RecoveryCodes::looksLikeCode('abcde-fghij'))->toBeTrue()
        ->and(RecoveryCodes::looksLikeCode('ABCDEFGHIJ'))->toBeTrue()
        ->and(RecoveryCodes::looksLikeCode('abcde-fghi'))->toBeFalse()
        ->and(RecoveryCodes::looksLikeCode('abcde-fghi1'))->toBeFalse()
        ->and(RecoveryCodes::looksLikeCode('123456'))->toBeFalse();
});
