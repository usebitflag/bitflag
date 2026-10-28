<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\GenerateRecoveryCodes;
use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\Totp;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;

const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

/**
 * Gives a person confirmed two-factor sign-in and returns their recovery codes.
 *
 * @return list<string>
 */
function enroll_two_factor(User $user): array
{
    MfaFactor::query()->create([
        'user_id' => $user->id,
        'type' => MfaFactorType::Totp,
        'secret' => TOTP_SECRET,
        'confirmed_at' => now(),
    ]);

    return app(GenerateRecoveryCodes::class)->handle($user->id);
}

/**
 * The authenticator code for the current moment, or for a number of steps away from it.
 */
function totp_now(int $stepsAway = 0): string
{
    return Totp::code(TOTP_SECRET, Totp::timestep(now()->getTimestamp()) + $stepsAway);
}

/**
 * Asserts a JSON request was refused as invalid at the given field and returns the response.
 */
function invalid(TestResponse $response, string $path, ?string $message = null): TestResponse
{
    $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $fields = collect($response->json('error.details.fields'))->where('path', $path);

    expect($fields->isNotEmpty())->toBeTrue("No validation error for [{$path}].");

    if ($message !== null) {
        expect($fields->pluck('message')->all())->toContain($message);
    }

    return $response;
}
