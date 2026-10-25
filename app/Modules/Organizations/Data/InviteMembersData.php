<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Shared\Capabilities\OrgRole;

final readonly class InviteMembersData
{
    /**
     * @param  list<string>  $emails
     * @param  list<InviteGrant>  $grants
     */
    public function __construct(
        public string $organizationId,
        public array $emails,
        public OrgRole $orgRole,
        public array $grants = [],
    ) {}
}
