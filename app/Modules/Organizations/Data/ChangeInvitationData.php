<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class ChangeInvitationData
{
    public function __construct(public string $organizationId, public string $invitationId) {}
}
