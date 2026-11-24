<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use App\Modules\Credentials\Domain\CredentialType;

/**
 * What a management key or personal token is bound to and the scopes it holds.
 */
final readonly class TokenGrant
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public CredentialType $type,
        public string $organizationId,
        public string $projectId,
        public ?string $environmentId,
        public array $scopes,
    ) {}
}
