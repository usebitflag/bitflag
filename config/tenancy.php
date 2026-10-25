<?php

declare(strict_types=1);
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;

/*
|--------------------------------------------------------------------------
| Table classification
|--------------------------------------------------------------------------
|
| Every table is classified here, and a test fails for a table that is not. Tenant tables carry a required
| organization_id and a model that uses BelongsToOrganization. Scoped tables hold both organization events and events
| about a person or the platform, so organization_id is required only when scope is "organization". Global tables
| belong to no organization.
|
*/

return [

    // Table => model class.
    'tenant' => [
        'projects' => Project::class,
        'environments' => Environment::class,
        'environment_policies' => EnvironmentPolicy::class,
        'organization_memberships' => OrganizationMembership::class,
        'project_memberships' => ProjectMembership::class,
        'audit_events' => 'App\Shared\Audit\AuditEvent',
        'idempotency_records' => 'App\Shared\Idempotency\IdempotencyRecord',
    ],

    'scoped' => [
        'outbox_messages',
        'outbox_receipts',
    ],

    'global' => [
        'organizations',
        'users',
        'staff_users',
        'sessions',
        // Owned by a person, not an organization.
        'mfa_factors',
        'mfa_recovery_codes',
        'trusted_browsers',
        'known_devices',
        'security_events',
        'password_reset_tokens',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ],

];
