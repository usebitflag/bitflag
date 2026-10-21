<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;

/**
 * A user with the given roles in a fresh organization and project.
 *
 * @return array{actor: Actor, user: User, organization: Organization, project: Project, environment: Environment}
 */
function person(?OrgRole $orgRole, ?ProjectRole $projectRole): array
{
    $organization = Organization::factory()->create();
    $project = Project::factory()->create(['organization_id' => $organization->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id]);
    $user = User::factory()->create();

    if ($orgRole !== null) {
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $orgRole]);
    }

    if ($projectRole !== null) {
        ProjectMembership::factory()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id, 'role' => $projectRole]);
    }

    return [
        'actor' => Actor::user($user->id, $user->name, Channel::Dashboard),
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'environment' => $environment,
    ];
}

function projectScope(array $p): ProjectScope
{
    return new ProjectScope($p['organization']->id, $p['project']->id);
}

function environmentScope(array $p): EnvironmentScope
{
    return new EnvironmentScope($p['organization']->id, $p['project']->id, $p['environment']->id);
}
