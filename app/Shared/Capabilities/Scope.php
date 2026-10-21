<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Where a capability is checked: an organization, a project in it, or an environment of that project.
 */
interface Scope
{
    public function organizationId(): string;
}
