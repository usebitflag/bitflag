<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SignInThrottle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Psr\Clock\ClockInterface;

/** A clock that only moves when told to, apart from the cache's own expiry. */
function throttle_with_clock(?DateTimeImmutable &$now = null): SignInThrottle
{
    $now = new DateTimeImmutable('2026-10-25 10:00:00 UTC');
    $holder = &$now;

    $clock = new class($holder) implements ClockInterface
    {
        public function __construct(private ?DateTimeImmutable &$now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };

    return new SignInThrottle(new Repository(new ArrayStore), $clock);
}

it('locks after five failures and not before', function () {
    $throttle = throttle_with_clock();

    foreach (range(1, 4) as $n) {
        expect($throttle->recordFailure('a@x.dev'))->toBe($n)->and($throttle->isLocked('a@x.dev'))->toBeFalse();
    }

    expect($throttle->recordFailure('a@x.dev'))->toBe(5)->and($throttle->isLocked('a@x.dev'))->toBeTrue();
});

it('lifts the lock fifteen minutes later by the application clock, whatever the cache thinks', function () {
    $throttle = throttle_with_clock($now);
    foreach (range(1, 5) as $n) {
        $throttle->recordFailure('a@x.dev');
    }

    $now = $now->modify('+14 minutes 59 seconds');
    expect($throttle->isLocked('a@x.dev'))->toBeTrue();

    $now = $now->modify('+2 seconds');
    expect($throttle->isLocked('a@x.dev'))->toBeFalse();
});

it('counts addresses separately, ignoring case and spaces', function () {
    $throttle = throttle_with_clock();
    foreach (range(1, 5) as $n) {
        $throttle->recordFailure(' A@X.dev ');
    }

    expect($throttle->isLocked('a@x.dev'))->toBeTrue()->and($throttle->isLocked('b@x.dev'))->toBeFalse();
});

it('forgets failures and the lock when cleared', function () {
    $throttle = throttle_with_clock();
    foreach (range(1, 5) as $n) {
        $throttle->recordFailure('a@x.dev');
    }

    $throttle->clear('a@x.dev');

    expect($throttle->isLocked('a@x.dev'))->toBeFalse()->and($throttle->recordFailure('a@x.dev'))->toBe(1);
});

it('keeps no address in the cache keys', function () {
    $store = new ArrayStore;
    $throttle = new SignInThrottle(new Repository($store), new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable;
        }
    });

    $throttle->recordFailure('secret.person@x.dev');

    $keys = (new ReflectionProperty($store, 'storage'))->getValue($store);

    expect(implode(' ', array_keys($keys)))->not->toContain('secret.person')->and(array_keys($keys))->each->toStartWith('signin:');
});
