<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

use App\Shared\Actors\Actor;

/**
 * What a credential's own scopes allow. A token can only ever narrow what its owner or project permits, never widen it.
 */
interface TokenScopeMap
{
    public function allows(Actor $actor, Capability $capability, Scope $scope): bool;
}
