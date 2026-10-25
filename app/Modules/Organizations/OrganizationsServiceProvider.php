<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Organizations\Actions\ReleaseQueuedInvitations;
use App\Modules\Organizations\Console\ExpireInvitationsCommand;
use App\Modules\Organizations\Jobs\SendInvitationEmail;
use App\Modules\Organizations\Queries\MembershipCapabilityResolver;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Capabilities\CapabilityResolver;
use App\Shared\Navigation\NavigationEntry;
use App\Shared\Navigation\NavigationGroup;
use App\Shared\Navigation\PortalNavigation;
use App\Shared\Outbox\ConsumerRegistry;
use Illuminate\Support\Facades\Event;
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
        $this->app->make(ConsumerRegistry::class)->register(SendInvitationEmail::class);
        $this->commands([ExpireInvitationsCommand::class]);

        // Project members are managed from the project's own settings.
        $this->app->make(PortalNavigation::class)->register(
            new NavigationEntry('settings', 'Settings', 'gear-six', NavigationGroup::Administration, 20, 'project.settings.members'),
        );

        // An invitation queued by someone who had not confirmed their email goes out when they do.
        Event::listen(EmailVerified::class, static function (EmailVerified $event): void {
            app(ReleaseQueuedInvitations::class)->handle($event->userId);
        });

        $this->app->make(AuditCatalog::class)->register(
            'organization.created',
            'organization.updated',
            'organization.security_changed',
            'invitation.created',
            'invitation.resent',
            'invitation.revoked',
            'invitation.accepted',
            'project_member.added',
            'org_member.role_changed',
            'org_member.removed',
            'project_member.self_added',
            'project_member.role_changed',
            'project_member.removed',
        );
    }
}
