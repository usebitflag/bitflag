<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\PruneSecurityState;
use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
    $this->user = User::factory()->create();
});

function event_at(string $when): void
{
    SecurityEvent::query()->create(['user_id' => test()->user->id, 'type' => SecurityEventType::SignInFailed, 'created_at' => $when]);
}

it('keeps security events for 90 days', function () {
    event_at(now()->subDays(89)->toDateTimeString());
    event_at(now()->subDays(91)->toDateTimeString());

    $result = app(PruneSecurityState::class)->handle();

    expect($result['security_events'])->toBe(1)
        ->and(SecurityEvent::query()->count())->toBe(1);
});

it('deletes sessions idle past seven days or older than thirty, and keeps the rest', function () {
    $rows = [
        ['id' => 'fresh', 'last_activity' => time(), 'created_at' => time()],
        ['id' => 'idle', 'last_activity' => time() - 8 * 86400, 'created_at' => time() - 9 * 86400],
        ['id' => 'old', 'last_activity' => time() - 60, 'created_at' => time() - 31 * 86400],
        ['id' => 'unknown-start', 'last_activity' => time() - 60, 'created_at' => 0],
    ];
    foreach ($rows as $row) {
        DB::table('sessions')->insert(['user_id' => $this->user->id, 'payload' => '', ...$row]);
    }

    $result = app(PruneSecurityState::class)->handle();

    expect($result['sessions'])->toBe(2)
        ->and(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['fresh', 'unknown-start']);
});

it('deletes expired trusted browsers and ones revoked more than a day ago', function () {
    $base = ['user_id' => $this->user->id, 'device_label' => 'x', 'created_at' => now(), 'revoked_at' => null];
    DB::table('trusted_browsers')->insert([
        ['id' => 'a'.str_repeat('0', 25), 'token_hash' => random_bytes(32), 'expires_at' => now()->addDays(5), ...$base],
        ['id' => 'b'.str_repeat('0', 25), 'token_hash' => random_bytes(32), 'expires_at' => now()->subHour(), ...$base],
        ['id' => 'c'.str_repeat('0', 25), 'token_hash' => random_bytes(32), 'expires_at' => now()->addDays(5), ...[...$base, 'revoked_at' => now()->subDays(2)]],
        ['id' => 'd'.str_repeat('0', 25), 'token_hash' => random_bytes(32), 'expires_at' => now()->addDays(5), ...[...$base, 'revoked_at' => now()->subMinute()]],
    ]);

    $result = app(PruneSecurityState::class)->handle();

    expect($result['trusted_browsers'])->toBe(2)
        ->and(DB::table('trusted_browsers')->pluck('id')->sort()->values()->all())->toBe(['a'.str_repeat('0', 25), 'd'.str_repeat('0', 25)]);
});

it('deletes enrollments left unconfirmed for a day but not confirmed factors', function () {
    $other = User::factory()->create();
    MfaFactor::query()->create(['user_id' => $this->user->id, 'type' => MfaFactorType::Totp, 'secret' => 'A'])->forceFill(['created_at' => now()->subDays(2)])->save();
    MfaFactor::query()->create(['user_id' => $other->id, 'type' => MfaFactorType::Totp, 'secret' => 'B', 'confirmed_at' => now()->subDays(40)])->forceFill(['created_at' => now()->subDays(40)])->save();

    $result = app(PruneSecurityState::class)->handle();

    expect($result['enrollments'])->toBe(1)
        ->and(MfaFactor::query()->count())->toBe(1);
});

it('runs as a command and is scheduled daily', function () {
    $this->artisan('identity:prune')->assertSuccessful();

    $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'identity:prune'));

    expect($scheduled)->not->toBeNull()->and($scheduled->expression)->toBe('30 3 * * *');
});
