<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;

/**
 * The demo world in miniature: Acme with Storefront and Checkout API, each with development, staging and production,
 * five people with different access, and a second organization for isolation checks.
 *
 * @return array<string, mixed>
 */
function portalWorld(): array
{
    $acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $globex = Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);

    $project = function (Organization $organization, string $name, string $key): Project {
        $project = Project::factory()->create(['organization_id' => $organization->id, 'name' => $name, 'key' => $key]);

        foreach (['development', 'staging', 'production'] as $position => $environment) {
            Environment::factory()->create([
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'name' => $environment,
                'key' => $environment,
                'position' => $position + 1,
            ]);
        }

        return $project;
    };

    $storefront = $project($acme, 'Storefront', 'storefront');
    $checkout = $project($acme, 'Checkout API', 'checkout-api');
    $globexProject = $project($globex, 'Billing', 'billing');

    $person = function (string $name, string $email, Organization $organization, OrgRole $orgRole, array $projectRoles = []): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $orgRole]);

        foreach ($projectRoles as [$project, $role]) {
            ProjectMembership::factory()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);
        }

        return $user;
    };

    return [
        'acme' => $acme,
        'globex' => $globex,
        'storefront' => $storefront,
        'checkout' => $checkout,
        'maya' => $person('Maya Santos', 'maya@acme.dev', $acme, OrgRole::Owner, [[$storefront, ProjectRole::Owner], [$checkout, ProjectRole::Owner]]),
        'jon' => $person('Jon Reyes', 'jon@acme.dev', $acme, OrgRole::Member, [[$storefront, ProjectRole::Publisher], [$checkout, ProjectRole::Developer]]),
        'ana' => $person('Ana Cruz', 'ana@acme.dev', $acme, OrgRole::Member, [[$storefront, ProjectRole::Viewer]]),
        'leo' => $person('Leo Ramos', 'leo@acme.dev', $acme, OrgRole::Member),
        'gina' => $person('Gina Park', 'gina@globex.dev', $globex, OrgRole::Owner, [[$globexProject, ProjectRole::Owner]]),
    ];
}
