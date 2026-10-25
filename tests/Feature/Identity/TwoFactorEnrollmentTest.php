<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\OrganizationMfaPolicy;
use App\Modules\Identity\Domain\SecurityEventType;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\SecurityEvent;
use App\Modules\Identity\Models\User;
use App\Shared\Outbox\OutboxMessageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create(['email' => 'maya@acme.dev', 'password' => 'correct horse battery']);
});

function start_enrollment(string $password = 'correct horse battery'): TestResponse
{
    return test()->actingAs(test()->user)->postJson('/account/two-factor', ['password' => $password]);
}

function confirm_enrollment(string $code): TestResponse
{
    return test()->actingAs(test()->user)->postJson('/account/two-factor/confirm', ['code' => $code]);
}

it('starts enrollment with the password and returns a secret, a link and a QR code that are not cached', function () {
    $response = start_enrollment()->assertOk();

    $secret = $response->json('secret');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('uri'))->toContain("secret={$secret}")->toContain('maya%40acme.dev')
        ->and($response->json('qr'))->toContain('<svg')
        ->and(MfaFactor::query()->where('user_id', $this->user->id)->whereNull('confirmed_at')->count())->toBe(1);
});

it('needs the password to start', function () {
    invalid(start_enrollment('wrong'), 'password');

    expect(MfaFactor::query()->count())->toBe(0);
});

it('stores the secret encrypted', function () {
    $secret = start_enrollment()->json('secret');

    expect((string) DB::table('mfa_factors')->value('secret'))->not->toContain($secret);
});

it('replaces an unconfirmed secret when started again', function () {
    $first = start_enrollment()->json('secret');
    $second = start_enrollment()->json('secret');

    expect($second)->not->toBe($first)
        ->and(MfaFactor::query()->count())->toBe(1);
});

it('refuses to start when two-factor is already on', function () {
    enroll_two_factor($this->user);

    invalid(start_enrollment(), 'two_factor');
});

it('confirms with a code from the app, turns two-factor on and returns ten recovery codes once', function () {
    $secret = start_enrollment()->json('secret');

    $response = confirm_enrollment(Totp::code($secret, Totp::timestep(now()->getTimestamp())))->assertOk();

    $codes = $response->json('recoveryCodes');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($codes)->toHaveCount(10)
        ->and($codes[0])->toMatch('/^[a-z2-7]{5}-[a-z2-7]{5}$/')
        ->and(MfaFactor::query()->whereNotNull('confirmed_at')->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::MfaEnabled)->count())->toBe(1)
        ->and(OutboxMessageRecord::query()->where('payload->template', 'mfa_enabled')->count())->toBe(1);
});

it('keeps recovery codes out of the database, the session and the queue', function () {
    $secret = start_enrollment()->json('secret');
    $codes = confirm_enrollment(Totp::code($secret, Totp::timestep(now()->getTimestamp())))->json('recoveryCodes');

    $stored = json_encode([
        DB::table('mfa_recovery_codes')->get()->map(fn ($r) => bin2hex($r->code_hash))->all(),
        session()->all(),
        OutboxMessageRecord::query()->get()->pluck('payload'),
        SecurityEvent::query()->get()->toArray(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    foreach ($codes as $code) {
        expect($stored)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
    }
});

it('has no route to see the codes again', function () {
    $this->actingAs($this->user)->get('/account/recovery-codes')->assertNotFound();
    $this->actingAs($this->user)->getJson('/account/two-factor')->assertNotFound();
});

it('does not confirm with a wrong code, or when nothing was started', function () {
    confirm_enrollment('123456')->assertStatus(422);

    $secret = start_enrollment()->json('secret');
    invalid(confirm_enrollment(Totp::code($secret, Totp::timestep(now()->getTimestamp()) + 5)), 'code');

    expect(MfaFactor::query()->whereNotNull('confirmed_at')->count())->toBe(0);
});

it('records the step used to confirm, so the same code cannot sign in', function () {
    $secret = start_enrollment()->json('secret');
    $step = Totp::timestep(now()->getTimestamp());
    confirm_enrollment(Totp::code($secret, $step));

    expect(MfaFactor::query()->firstOrFail()->last_used_timestep)->toBe($step);
});

it('makes a new set of recovery codes with the password, and the old set stops working', function () {
    $old = enroll_two_factor($this->user);

    $this->actingAs($this->user)->postJson('/account/recovery-codes', ['password' => 'wrong'])->assertStatus(422);
    $new = $this->actingAs($this->user)->postJson('/account/recovery-codes', ['password' => 'correct horse battery'])->assertOk()->json('recoveryCodes');

    expect($new)->toHaveCount(10)
        ->and(array_intersect($new, $old))->toBe([])
        ->and(MfaRecoveryCode::query()->count())->toBe(10);
});

it('will not make recovery codes without two-factor', function () {
    invalid($this->actingAs($this->user)->postJson('/account/recovery-codes', ['password' => 'correct horse battery']), 'two_factor');
});

it('turns two-factor off with the password and a current code, and says so by email', function () {
    enroll_two_factor($this->user);
    DB::table('trusted_browsers')->insert([
        'id' => strtolower((string) Str::ulid()), 'user_id' => $this->user->id, 'token_hash' => random_bytes(32),
        'device_label' => 'x', 'created_at' => now(), 'expires_at' => now()->addDays(30),
    ]);
    $this->travel(31)->seconds();

    $this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'correct horse battery', 'code' => totp_now()])->assertOk();

    expect(MfaFactor::query()->count())->toBe(0)
        ->and(MfaRecoveryCode::query()->count())->toBe(0)
        ->and(DB::table('trusted_browsers')->whereNull('revoked_at')->count())->toBe(0)
        ->and(SecurityEvent::query()->where('type', SecurityEventType::MfaDisabled)->count())->toBe(1)
        ->and(OutboxMessageRecord::query()->where('payload->template', 'mfa_disabled')->count())->toBe(1);
});

it('turns two-factor off with a recovery code instead of an app code', function () {
    $codes = enroll_two_factor($this->user);

    $this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'correct horse battery', 'code' => $codes[0]])->assertOk();

    expect(MfaFactor::query()->count())->toBe(0);
});

it('needs both the password and a right code to turn it off', function () {
    enroll_two_factor($this->user);

    invalid($this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'wrong', 'code' => totp_now()]), 'password');
    invalid($this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'correct horse battery', 'code' => '000000']), 'code');

    expect(MfaFactor::query()->count())->toBe(1);
});

it('does not spend the code when turning off fails', function () {
    $codes = enroll_two_factor($this->user);

    $this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'wrong', 'code' => $codes[0]]);

    expect(MfaRecoveryCode::query()->whereNull('used_at')->count())->toBe(10);
});

it('refuses to turn it off while an organization requires it', function () {
    enroll_two_factor($this->user);
    app()->bind(OrganizationMfaPolicy::class, fn () => new class implements OrganizationMfaPolicy
    {
        public function organizationsRequiringMfa(string $userId): array
        {
            return ['Acme'];
        }
    });

    $response = $this->actingAs($this->user)->deleteJson('/account/two-factor', ['password' => 'correct horse battery', 'code' => totp_now()]);

    invalid($response, 'two_factor', 'Acme requires two-factor sign-in.');
    expect(MfaFactor::query()->count())->toBe(1);
});

it('shows the security page with the state of two-factor', function () {
    $this->actingAs($this->user)->get('/account/security')->assertInertia(fn ($page) => $page
        ->component('account/Security')->where('twoFactor.enabled', false)->where('twoFactor.recoveryCodesLeft', 0)->where('requiredBy', null));

    enroll_two_factor($this->user);

    $this->actingAs($this->user)->get('/account/security?required_by=Acme')->assertInertia(fn ($page) => $page
        ->where('twoFactor.enabled', true)->where('twoFactor.recoveryCodesLeft', 10)->where('requiredBy', 'Acme'));
});

it('is for signed-in people only', function () {
    $this->postJson('/account/two-factor', ['password' => 'x'])->assertUnauthorized();
    $this->get('/account/security')->assertRedirect('/signin');
});
