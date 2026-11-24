<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

final readonly class IssueCredentialData
{
    /**
     * @param  string  $type  server, browser or management
     * @param  list<string>  $scopes
     * @param  int|null  $ttlDays  null means the credential never expires
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public string $type,
        public string $label,
        public array $scopes,
        public ?int $ttlDays,
    ) {}
}
