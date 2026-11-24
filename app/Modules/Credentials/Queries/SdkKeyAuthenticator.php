<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Data\ResolvedSdkCredential;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\WrongCredentialType;

/** Authenticates an SDK key and fixes the tenant, environment and snapshot projection it may use. */
final class SdkKeyAuthenticator
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function authenticate(string $bearer): ResolvedSdkCredential
    {
        $credential = $this->credentials->resolve($bearer);

        if (! $credential->type->isSdkKey() || $credential->environmentId === null) {
            throw new WrongCredentialType('Only server and browser keys can call the SDK API.');
        }

        return new ResolvedSdkCredential(
            $credential->id,
            $credential->organizationId,
            $credential->projectId,
            $credential->environmentId,
            $credential->type,
            $credential->type === CredentialType::Server ? 'server' : 'public',
            $credential->scopes,
        );
    }
}
