<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use App\Modules\Credentials\Domain\CredentialScope;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\ScopeMissing;

/** The tenant, environment and projection fixed by an authenticated server or browser key. */
final readonly class ResolvedSdkCredential
{
    /**
     * @param  'server'|'public'  $projection
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public CredentialType $type,
        public string $projection,
        public array $scopes,
    ) {}

    public function isBrowser(): bool
    {
        return $this->type === CredentialType::Browser;
    }

    /** @throws ScopeMissing */
    public function requireScope(CredentialScope $scope): void
    {
        if (! in_array($scope->value, $this->scopes, true)
            || ($scope === CredentialScope::Evaluate && $this->type !== CredentialType::Server)) {
            throw new ScopeMissing;
        }
    }
}
