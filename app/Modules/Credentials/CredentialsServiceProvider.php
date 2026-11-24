<?php

declare(strict_types=1);

namespace App\Modules\Credentials;

use App\Modules\Credentials\Console\CompleteCredentialRotationsCommand;
use App\Modules\Credentials\Console\ExpireCredentialsCommand;
use App\Modules\Credentials\Console\FlushCredentialLastUsedCommand;
use App\Modules\Credentials\Console\NotifyExpiringCredentialsCommand;
use App\Modules\Credentials\Console\PepperUsageCommand;
use App\Modules\Credentials\Jobs\RepairCredentialCache;
use App\Modules\Credentials\Listeners\RevokeTokensWhenMemberLeaves;
use App\Modules\Credentials\Support\CredentialCache;
use App\Modules\Organizations\Events\MemberRemovedFromOrganization;
use App\Modules\Organizations\Events\MemberRemovedFromProject;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Outbox\ConsumerRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Credentials module. The module owns its models, actions, queries and policies;
 * other modules reach it only through its public actions, data classes, queries and events.
 */
final class CredentialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialCache::class);
    }

    public function boot(): void
    {
        $this->app->make(AuditCatalog::class)->register('key.created', 'key.rotated', 'key.revoked', 'key.expired', 'token.created', 'token.revoked');
        Event::listen(MemberRemovedFromProject::class, [RevokeTokensWhenMemberLeaves::class, 'handleProject']);
        Event::listen(MemberRemovedFromOrganization::class, [RevokeTokensWhenMemberLeaves::class, 'handleOrganization']);
        $this->app->make(ConsumerRegistry::class)->register(RepairCredentialCache::class);

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCredentialLastUsedCommand::class, CompleteCredentialRotationsCommand::class, ExpireCredentialsCommand::class, NotifyExpiringCredentialsCommand::class, PepperUsageCommand::class]);
        }
    }
}
