<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;

/**
 * Stores a credential the way issuing one will, and hands back the secret the holder would have. For tests of what happens
 * after a credential exists.
 *
 * @param  array<string, mixed>  $change
 * @return array{secret: string, credential: Credential}
 */
function make_credential(array $p, array $change = [], CredentialType $type = CredentialType::Server): array
{
    $generated = (new TokenGenerator)->generate($type, 'dev');

    $credential = Tenancy::crossTenant('inspect', fn () => Credential::factory()->create([
        'environment_id' => $type === CredentialType::Personal ? null : $p['environment']->id,
        'project_id' => $p['project']->id,
        'organization_id' => $p['organization']->id,
        'type' => $type,
        'user_id' => $type === CredentialType::Personal ? $p['user']->id : null,
        'display_prefix' => $generated->displayPrefix,
        'last4' => $generated->last4,
        'secret_hash' => app(SecretHasher::class)->hash($generated->secret),
        ...$change,
    ]));

    return ['secret' => $generated->secret, 'credential' => $credential];
}

function credential_now(Credential $credential): Credential
{
    return Tenancy::crossTenant('inspect', fn () => Credential::query()->findOrFail($credential->id));
}
