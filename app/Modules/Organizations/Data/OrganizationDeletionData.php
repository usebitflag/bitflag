<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Data;

final readonly class OrganizationDeletionData
{
    public function __construct(
        public string $organizationId,
        /** The organization's URL name, typed to confirm scheduling its deletion. */
        public ?string $typedSlug = null,
    ) {}
}
