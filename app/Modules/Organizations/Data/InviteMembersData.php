<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

use App\Modules\Organizations\Domain\InvitationRules;
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

    /**
     * Splits what a person typed or pasted into addresses: on commas, semicolons, spaces and line breaks, lower-cased and
     * without duplicates.
     *
     * @return list<string>
     */
    public static function emailsFromText(string $text): array
    {
        return InvitationRules::parseEmails($text);
    }
}
