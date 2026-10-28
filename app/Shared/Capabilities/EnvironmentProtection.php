<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Whether an environment is protected. Protected environments can be published only by roles that hold
 * environment.publish.protected. The Environments module answers from the current version of the environment's policy.
 */
interface EnvironmentProtection
{
    public function isProtected(EnvironmentScope $environment): bool;
}
