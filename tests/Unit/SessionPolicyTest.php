<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\SessionPolicy;

const DAY = 86400;

it('uses 7 days idle and 30 days absolute by default', function () {
    expect(SessionPolicy::PLATFORM_IDLE_MINUTES)->toBe(7 * 24 * 60)
        ->and(SessionPolicy::ABSOLUTE_MINUTES)->toBe(30 * 24 * 60)
        ->and(SessionPolicy::ORGANIZATION_IDLE_OPTIONS)->toBe([60, 720, 1440]);
});

it('lets a session continue inside both limits', function () {
    $now = 100 * DAY;

    expect(SessionPolicy::expiry($now - 20 * DAY, $now - 6 * DAY, $now, null))->toBeNull();
});

it('ends a session idle for more than seven days', function () {
    $now = 100 * DAY;

    expect(SessionPolicy::expiry($now - 10 * DAY, $now - 7 * DAY - 1, $now, null))->toBe(['reason' => 'idle', 'limit_minutes' => 10080])
        ->and(SessionPolicy::expiry($now - 10 * DAY, $now - 7 * DAY, $now, null))->toBeNull();
});

it('ends a session older than thirty days however active it is', function () {
    $now = 100 * DAY;

    expect(SessionPolicy::expiry($now - 30 * DAY - 1, $now - 60, $now, null))->toBe(['reason' => 'absolute', 'limit_minutes' => 43200])
        ->and(SessionPolicy::expiry($now - 30 * DAY, $now - 60, $now, null))->toBeNull();
});

it('reports the absolute limit when both have run out', function () {
    $now = 100 * DAY;

    expect(SessionPolicy::expiry($now - 40 * DAY, $now - 20 * DAY, $now, null)['reason'])->toBe('absolute');
});

it('lets an organization tighten the idle limit but never loosen it', function () {
    $now = 100 * DAY;

    expect(SessionPolicy::idleMinutes(720))->toBe(720)
        ->and(SessionPolicy::idleMinutes(null))->toBe(10080)
        ->and(SessionPolicy::idleMinutes(99999))->toBe(10080)
        ->and(SessionPolicy::expiry($now - DAY, $now - 13 * 3600, $now, 720))->toBe(['reason' => 'idle', 'limit_minutes' => 720])
        ->and(SessionPolicy::expiry($now - DAY, $now - 11 * 3600, $now, 720))->toBeNull();
});

it('describes limits as a person reads them', function (int $minutes, string $text) {
    expect(SessionPolicy::describe($minutes))->toBe($text);
})->with([[60, '1 hour'], [720, '12 hours'], [1440, '1 day'], [10080, '7 days'], [43200, '30 days'], [45, '45 minutes']]);

it('words the message by reason', function () {
    expect(SessionPolicy::message('idle', 720))->toBe('Your session ended after 12 hours of inactivity.')
        ->and(SessionPolicy::message('absolute', 43200))->toBe('Your session ended after 30 days.');
});
