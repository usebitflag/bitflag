<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\OrgRole;

/**
 * An invitation as the person invited, or an email about it, describes it. `state` is `open`, `expired`, `revoked`, `used`
 * or `unknown` (no invitation has that link, which looks the same as a withdrawn one to anyone but its sender).
 */
final readonly class InvitationSummary
{
    /**
     * @param  list<array{name: string, role: string}>  $projects
     */
    public function __construct(
        public string $state,
        public ?string $invitationId = null,
        public ?string $organizationId = null,
        public ?string $email = null,
        public ?string $organizationName = null,
        public ?string $inviterName = null,
        public ?OrgRole $orgRole = null,
        public array $projects = [],
        public ?int $expiresAt = null,
    ) {}

    /**
     * "j•••@acme.dev": enough for the owner of the address to recognize it.
     */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $this->email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
