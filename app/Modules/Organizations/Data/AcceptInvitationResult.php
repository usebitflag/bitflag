<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class AcceptInvitationResult
{
    /**
     * @param  list<string>  $addedProjectIds  projects the person was added to
     * @param  list<string>  $existingProjectIds  projects they already belonged to, with the role they already had
     */
    public function __construct(
        public string $userId,
        public string $organizationId,
        public string $organizationSlug,
        public bool $createdAccount,
        public array $addedProjectIds,
        public array $existingProjectIds,
    ) {}
}
