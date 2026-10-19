<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Modules\Organizations\Queries\MembershipCapabilityResolver;
use App\Shared\Capabilities\CapabilityResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Organizations module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class OrganizationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolver per request or job, so remembered memberships never outlive them.
        $this->app->scoped(CapabilityResolver::class, MembershipCapabilityResolver::class);
    }

    public function boot(): void
    {
        //
    }
}
