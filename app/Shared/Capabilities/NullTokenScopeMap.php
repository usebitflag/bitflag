<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

use App\Shared\Actors\Actor;

/**
 * Grants nothing until credentials define their scopes, so a token can do no more than nothing.
 */
final class NullTokenScopeMap implements TokenScopeMap
{
    public function allows(Actor $actor, Capability $capability, Scope $scope): bool
    {
        return false;
    }
}
