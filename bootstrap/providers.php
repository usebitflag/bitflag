<?php

declare(strict_types=1);

use App\Admin\AdminPanelProvider;
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
    AdminPanelProvider::class,
    AnalyticsServiceProvider::class,
    AuditServiceProvider::class,
    BillingServiceProvider::class,
    CredentialsServiceProvider::class,
    EnvironmentsServiceProvider::class,
    ExperimentsServiceProvider::class,
    FlagsServiceProvider::class,
    IdentityServiceProvider::class,
    IntegrationsServiceProvider::class,
    NotificationsServiceProvider::class,
    OrganizationsServiceProvider::class,
    ProjectsServiceProvider::class,
    PublishingServiceProvider::class,
    SupportServiceProvider::class,
    TargetingServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
