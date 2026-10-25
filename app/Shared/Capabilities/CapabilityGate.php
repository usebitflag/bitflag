<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Lets controllers and views ask Laravel's Gate about a capability, with the scope as the argument:
 *
 *   Gate::authorize('org.manage', new OrganizationScope($organizationId));
 *
 * Every ability is one capability from the conventions table, answered by the same resolver as Actor::can(), so there is no
 * second set of rules. Roles never appear here.
 */
final class CapabilityGate
{
    public static function register(): void
    {
        foreach (Capability::cases() as $capability) {
            Gate::define($capability->value, static function (Authenticatable $user, Scope $scope) use ($capability): bool {
                $id = $user->getAuthIdentifier();
                $name = $user instanceof Model ? $user->getAttribute('name') : null;

                return is_string($id) && Actor::user($id, is_string($name) ? $name : '', Channel::Dashboard)->can($capability, $scope);
            });
        }
    }
}
