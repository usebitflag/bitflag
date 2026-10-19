<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

/**
 * The demo people, covering every project role and a person with no project access.
 *
 * Maya owns Acme and both projects. Jon publishes to Storefront and develops Checkout API. Priya develops on
 * Storefront. Ana can only view Storefront. Leo belongs to Acme with no project. Gina owns Globex.
 */
final class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            1 => ['Maya Santos', 'maya@acme.dev', OrganizationsSeeder::ACME, OrgRole::Owner, [ProjectsSeeder::STOREFRONT => ProjectRole::Owner, ProjectsSeeder::CHECKOUT_API => ProjectRole::Owner]],
            2 => ['Jon Reyes', 'jon@acme.dev', OrganizationsSeeder::ACME, OrgRole::Member, [ProjectsSeeder::STOREFRONT => ProjectRole::Publisher, ProjectsSeeder::CHECKOUT_API => ProjectRole::Developer]],
            3 => ['Priya Nair', 'priya@acme.dev', OrganizationsSeeder::ACME, OrgRole::Member, [ProjectsSeeder::STOREFRONT => ProjectRole::Developer]],
            4 => ['Ana Cruz', 'ana@acme.dev', OrganizationsSeeder::ACME, OrgRole::Member, [ProjectsSeeder::STOREFRONT => ProjectRole::Viewer]],
            5 => ['Leo Ramos', 'leo@acme.dev', OrganizationsSeeder::ACME, OrgRole::Member, []],
            6 => ['Gina Park', 'gina@globex.dev', OrganizationsSeeder::GLOBEX, OrgRole::Owner, [ProjectsSeeder::BILLING => ProjectRole::Owner]],
        ];

        $password = Config::string('bitflag.demo_password');
        $membership = 0;

        foreach ($people as $number => [$name, $email, $organization, $orgRole, $projectRoles]) {
            $user = User::query()->find(DemoIds::user($number)) ?? new User;
            $user->forceFill(['id' => DemoIds::user($number), 'name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => now(), 'status' => 'active'])->save();

            $organizationId = DemoIds::organization($organization);

            app(TenantContext::class)->run($organizationId, function () use ($number, $user, $organizationId, $orgRole, $projectRoles, &$membership): void {
                $record = OrganizationMembership::query()->find(DemoIds::organizationMembership($number)) ?? new OrganizationMembership;
                $record->forceFill(['id' => DemoIds::organizationMembership($number), 'organization_id' => $organizationId, 'user_id' => $user->id, 'role' => $orgRole])->save();

                foreach ($projectRoles as $project => $role) {
                    $membership++;
                    $record = ProjectMembership::query()->find(DemoIds::projectMembership($membership)) ?? new ProjectMembership;
                    $record->forceFill([
                        'id' => DemoIds::projectMembership($membership),
                        'organization_id' => $organizationId,
                        'project_id' => DemoIds::project($project),
                        'user_id' => $user->id,
                        'role' => $role,
                    ])->save();
                }
            });
        }
    }
}
