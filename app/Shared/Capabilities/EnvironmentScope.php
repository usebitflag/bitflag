<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

final readonly class EnvironmentScope implements Scope
{
    public function __construct(private string $organizationId, public string $projectId, public string $environmentId) {}

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
