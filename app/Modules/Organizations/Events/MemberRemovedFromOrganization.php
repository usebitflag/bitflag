<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Events;

/**
 * Someone no longer belongs to an organization. Whatever they held there that depends on belonging, such as personal tokens,
 * is revoked in response.
 */
final readonly class MemberRemovedFromOrganization
{
    public function __construct(public string $organizationId, public string $userId) {}
}
