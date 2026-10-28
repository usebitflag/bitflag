<?php

declare(strict_types=1);

use App\Modules\Identity\Models\KnownDevice;
use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse']);
});

function sign_in_from(string $userAgent, ?string $deviceCookie = null): TestResponse
{
    $request = test()->withHeader('User-Agent', $userAgent);

    if ($deviceCookie !== null) {
        $request = $request->withCookie('bf_dev', $deviceCookie);
    }

    return $request->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'correct horse']);
}

function new_signin_emails(): int
{
    return OutboxMessageRecord::query()->where('payload->template', 'new_signin')->count();
}

it('remembers the first device without an email, and sets a long-lived cookie', function () {
    $response = sign_in_from(MAC_CHROME);

    $cookie = $response->getCookie('bf_dev', false);

    expect(KnownDevice::query()->count())->toBe(1)
        ->and(KnownDevice::query()->firstOrFail()->device_label)->toBe('Chrome on macOS')
        ->and(new_signin_emails())->toBe(0)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(399)->getTimestamp());
});

it('stores only a hash of the device value', function () {
    $value = sign_in_from(MAC_CHROME)->getCookie('bf_dev')->getValue();

    expect(DB::table('known_devices')->get()->map(fn ($r) => json_encode((array) $r, JSON_INVALID_UTF8_SUBSTITUTE))->implode(''))->not->toContain($value);
});

it('does not email about a device it has seen', function () {
    $value = sign_in_from(MAC_CHROME)->getCookie('bf_dev')->getValue();
    $this->post('/signout');

    $second = sign_in_from(MAC_CHROME, $value);

    expect(new_signin_emails())->toBe(0)
        ->and(KnownDevice::query()->count())->toBe(1)
        ->and($second->getCookie('bf_dev')->getValue())->toBe($value);
});

it('emails once about a device it has not seen, naming it', function () {
    sign_in_from(MAC_CHROME);
    $this->post('/signout');

    sign_in_from(IPHONE_SAFARI);

    $message = OutboxMessageRecord::query()->where('payload->template', 'new_signin')->firstOrFail();

    expect(new_signin_emails())->toBe(1)
        ->and($message->payload['details'])->toBe(['device_label' => 'Safari on iOS'])
        ->and(KnownDevice::query()->count())->toBe(2);
});

it('treats a missing or forged cookie as a new device', function (?string $cookie) {
    sign_in_from(MAC_CHROME);
    $this->post('/signout');

    sign_in_from(MAC_CHROME, $cookie);

    expect(new_signin_emails())->toBe(1);
})->with(['none' => [null], 'garbage' => ['not-a-device'], 'short' => [str_repeat('a', 42)]]);

it('does not email about a device when the password was wrong', function () {
    sign_in_from(MAC_CHROME);
    $this->post('/signout');

    $this->withHeader('User-Agent', IPHONE_SAFARI)->post('/signin', ['email' => 'maya@acme.dev', 'password' => 'wrong']);

    expect(new_signin_emails())->toBe(0);
});

it('records the device after the second factor, not before', function () {
    enroll_two_factor($this->user);
    sign_in_from(MAC_CHROME);

    expect(KnownDevice::query()->count())->toBe(0);

    $this->withHeader('User-Agent', MAC_CHROME)->post('/two-factor-challenge', ['code' => totp_now()]);

    expect(KnownDevice::query()->count())->toBe(1);
});
