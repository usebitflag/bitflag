<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

use App\Shared\Actors\Actor;

/**
 * Answers whether an actor holds a capability in a scope. Policies and actions ask through Actor::can(), which
 * delegates here; the server rechecks every portal permission the browser was told about.
 */
interface CapabilityResolver
{
    public function can(Actor $actor, Capability $capability, Scope $scope): bool;
}
