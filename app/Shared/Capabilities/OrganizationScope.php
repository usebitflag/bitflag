<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

final readonly class OrganizationScope implements Scope
{
    public function __construct(private string $organizationId) {}

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
