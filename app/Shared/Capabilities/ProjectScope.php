<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

final readonly class ProjectScope implements Scope
{
    public function __construct(private string $organizationId, public string $projectId) {}

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
