<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Whether an environment is protected. Protected environments can be published only by roles that hold
 * environment.publish.protected. The default binding treats every environment as unprotected until protection policies
 * exist.
 */
interface EnvironmentProtection
{
    public function isProtected(EnvironmentScope $environment): bool;
}
