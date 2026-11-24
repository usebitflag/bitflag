<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Environments\Models\Environment;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes credential rows. The secret is not stored, so a test that needs one builds it with TokenGenerator and sets
 * `secret_hash` from SecretHasher.
 *
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'project_id' => fn (array $attributes) => Tenancy::crossTenant('factory reads the environment', fn () => Environment::query()->whereKey($attributes['environment_id'])->firstOrFail()->project_id),
            'organization_id' => fn (array $attributes) => Tenancy::crossTenant('factory reads the environment', fn () => Environment::query()->whereKey($attributes['environment_id'])->firstOrFail()->organization_id),
            'type' => CredentialType::Server,
            'label' => 'Checkout API',
            'display_prefix' => 'bf_srv_dev_',
            'last4' => 'ab12',
            'secret_hash' => fn () => random_bytes(32),
            'pepper_id' => app(SecretHasher::class)->currentPepperId(),
            'scopes' => ['snapshot:read'],
            'status' => CredentialStatus::Active,
            'audience' => CredentialAudience::Api,
        ];
    }
}
