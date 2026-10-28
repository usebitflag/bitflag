<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

final readonly class IssuePersonalAccessTokenData
{
    /**
     * @param  string  $accessLevel  read, draft or publish
     * @param  int|null  $ttlDays  null means the token never expires
     */
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $label,
        public string $accessLevel,
        public ?int $ttlDays,
    ) {}
}
