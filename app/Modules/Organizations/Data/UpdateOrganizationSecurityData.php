<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class UpdateOrganizationSecurityData
{
    /**
     * @param  list<string>  $allowedEmailDomains
     */
    public function __construct(
        public string $organizationId,
        public bool $requireMfa,
        public ?int $idleTimeoutMinutes,
        public array $allowedEmailDomains,
        public int $expectedVersion,
    ) {}
}
