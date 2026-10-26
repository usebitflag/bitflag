<?php

declare(strict_types=1);

use App\Shared\Mail\MailTime;

it('states a moment in the person\'s zone with UTC beside it', function (string $zone, string $expected) {
    expect(MailTime::format(new DateTimeImmutable('2026-10-06 09:42:00', new DateTimeZone('UTC')), $zone))->toBe($expected);
})->with([
    'UTC' => ['UTC', '6 Oct 2026, 09:42 UTC'],
    'Manila' => ['Asia/Manila', '6 Oct 2026, 17:42 UTC+8 (09:42 UTC)'],
    'New York in summer time' => ['America/New_York', '6 Oct 2026, 05:42 UTC-4 (09:42 UTC)'],
    'India, a half-hour zone' => ['Asia/Kolkata', '6 Oct 2026, 15:12 UTC+5:30 (09:42 UTC)'],
    'London on UTC in winter' => ['Europe/London', '6 Oct 2026, 10:42 UTC+1 (09:42 UTC)'],
    'an unknown zone falls back to UTC' => ['Not/AZone', '6 Oct 2026, 09:42 UTC'],
]);

it('says UTC once when the zone is on UTC at that moment', function () {
    expect(MailTime::format(new DateTimeImmutable('2026-01-06 09:42:00', new DateTimeZone('UTC')), 'Europe/London'))->toBe('6 Jan 2026, 09:42 UTC');
});
