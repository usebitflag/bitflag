<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores the second-factor secret encrypted', function () {
    $user = User::factory()->create();
    $factor = MfaFactor::query()->create(['user_id' => $user->id, 'type' => MfaFactorType::Totp, 'secret' => 'JBSWY3DPEHPK3PXP']);

    $stored = (string) DB::table('mfa_factors')->where('id', $factor->id)->value('secret');

    expect($stored)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($factor->fresh()?->secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and($factor->toArray())->not->toHaveKey('secret');
});

it('allows one factor of a kind per person', function () {
    $user = User::factory()->create();
    MfaFactor::query()->create(['user_id' => $user->id, 'type' => MfaFactorType::Totp, 'secret' => 'a']);

    expect(fn () => MfaFactor::query()->create(['user_id' => $user->id, 'type' => MfaFactorType::Totp, 'secret' => 'b']))
        ->toThrow(QueryException::class);
});

it('keeps security events after the account row goes, without the person', function () {
    $user = User::factory()->create();
    $event = SecurityEvent::query()->create(['user_id' => $user->id, 'type' => SecurityEventType::SignInFailed, 'created_at' => now()]);

    DB::table('users')->where('id', $user->id)->delete();

    expect($event->fresh()?->user_id)->toBeNull();
});

it('has no remember token, because there is no remember me', function () {
    expect(Schema::hasColumn('users', 'remember_token'))->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user)->post('/signout');

    expect(User::query()->find($user->id))->not->toBeNull();
});
