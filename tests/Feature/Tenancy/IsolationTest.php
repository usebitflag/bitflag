<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;

it('isolates projects', function () {
    assertTenantIsolated(Project::class, fn (Organization $organization) => Project::factory()->create(['organization_id' => $organization->id]));
});

it('isolates environments', function () {
    assertTenantIsolated(Environment::class, function (Organization $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Environment::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id]);
    });
});

it('isolates organization memberships', function () {
    assertTenantIsolated(OrganizationMembership::class, fn (Organization $organization) => OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory()->create()->id,
    ]));
});

it('isolates project memberships', function () {
    assertTenantIsolated(ProjectMembership::class, function (Organization $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return ProjectMembership::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
        ]);
    });
});
