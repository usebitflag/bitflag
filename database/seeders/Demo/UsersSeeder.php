<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\MfaFactorType;
use App\Modules\Identity\Domain\RecoveryCodes;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

/**
 * The demo people, covering every project role and a person with no project access.
 *
 * Maya owns Acme and both projects, owns her own organization Maya Studio too, and her last-used scope is Storefront in
 * production, so signing in takes her there without asking which organization. Maya and Jon use two-factor. The others
 * have no remembered scope. Jon publishes to Storefront and develops Checkout API. Priya develops on
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
        $remembered = [1 => [OrganizationsSeeder::ACME, ProjectsSeeder::STOREFRONT, 3]];
        $twoFactor = [1, 2];

        foreach ($people as $number => [$name, $email, $organization, $orgRole, $projectRoles]) {
            $user = User::query()->find(DemoIds::user($number)) ?? new User;
            $user->forceFill(['id' => DemoIds::user($number), 'name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => now(), 'status' => AccountStatus::Active])->save();

            $organizationId = DemoIds::organization($organization);

            if (isset($remembered[$number])) {
                [$lastOrganization, $lastProject, $lastPosition] = $remembered[$number];
                $user->forceFill([
                    'remember_org_choice' => true,
                    'last_organization_id' => DemoIds::organization($lastOrganization),
                    'last_project_id' => DemoIds::project($lastProject),
                    'last_environment_id' => DemoIds::environment($lastProject, $lastPosition),
                ])->save();
            }

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

            if (in_array($number, $twoFactor, true)) {
                $this->twoFactor($user);
            }
        }

        $this->studio();
    }

    /**
     * Confirmed two-factor with a known secret and recovery codes, so specs can sign in with a code.
     */
    private function twoFactor(User $user): void
    {
        MfaFactor::query()->where('user_id', $user->id)->delete();
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        MfaFactor::query()->create(['user_id' => $user->id, 'type' => MfaFactorType::Totp, 'secret' => DemoSecrets::TOTP_SECRET, 'confirmed_at' => now()]);

        foreach (DemoSecrets::RECOVERY_CODES as $code) {
            MfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => app(SecretHasher::class)->hash(RecoveryCodes::normalize($code)),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Maya's own organization and project.
     */
    private function studio(): void
    {
        $organizationId = DemoIds::organization(OrganizationsSeeder::STUDIO);
        $mayaId = DemoIds::user(1);

        app(TenantContext::class)->run($organizationId, function () use ($organizationId, $mayaId): void {
            $membership = OrganizationMembership::query()->find(DemoIds::organizationMembership(7)) ?? new OrganizationMembership;
            $membership->forceFill(['id' => DemoIds::organizationMembership(7), 'organization_id' => $organizationId, 'user_id' => $mayaId, 'role' => OrgRole::Owner])->save();

            $project = ProjectMembership::query()->find(DemoIds::projectMembership(8)) ?? new ProjectMembership;
            $project->forceFill([
                'id' => DemoIds::projectMembership(8),
                'organization_id' => $organizationId,
                'project_id' => DemoIds::project(ProjectsSeeder::PORTFOLIO),
                'user_id' => $mayaId,
                'role' => ProjectRole::Owner,
            ])->save();
        });
    }
}
