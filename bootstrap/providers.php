<?php

declare(strict_types=1);
use App\Modules\Analytics\AnalyticsServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Credentials\CredentialsServiceProvider;
use App\Modules\Environments\EnvironmentsServiceProvider;
use App\Modules\Experiments\ExperimentsServiceProvider;
use App\Modules\Flags\FlagsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Integrations\IntegrationsServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Organizations\OrganizationsServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Publishing\PublishingServiceProvider;
use App\Modules\Support\SupportServiceProvider;
use App\Modules\Targeting\TargetingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationsServiceProvider::class,
    ProjectsServiceProvider::class,
    EnvironmentsServiceProvider::class,
    FlagsServiceProvider::class,
    TargetingServiceProvider::class,
    PublishingServiceProvider::class,
    CredentialsServiceProvider::class,
    ExperimentsServiceProvider::class,
    AnalyticsServiceProvider::class,
    IntegrationsServiceProvider::class,
    AuditServiceProvider::class,
    BillingServiceProvider::class,
    NotificationsServiceProvider::class,
    SupportServiceProvider::class,
];
