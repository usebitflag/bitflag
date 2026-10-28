<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\InvitationProjectGrant;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * One invitation waiting in Acme: dev@northwind.studio, as a Developer on Storefront, invited by Maya. Its link is
 * `/invitations/{DemoSecrets::INVITATION_TOKEN}`, good for seven days from the seed.
 */
final class InvitationsSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = DemoIds::organization(OrganizationsSeeder::ACME);

        app(TenantContext::class)->run($organizationId, function () use ($organizationId): void {
            $invitation = Invitation::query()->find(DemoIds::invitation(1)) ?? new Invitation;
            $invitation->forceFill([
                'id' => DemoIds::invitation(1),
                'organization_id' => $organizationId,
                'email' => 'dev@northwind.studio',
                'org_role' => OrgRole::Member,
                'status' => InvitationStatus::Pending,
                'token_hash' => app(SecretHasher::class)->hash(DemoSecrets::INVITATION_TOKEN),
                'invited_by' => DemoIds::user(1),
                'sent_at' => now(),
                'expires_at' => now()->addDays(7),
                'accepted_by' => null,
                'accepted_at' => null,
                'revoked_by' => null,
                'revoked_at' => null,
            ])->save();

            InvitationProjectGrant::query()->where('invitation_id', $invitation->id)->delete();
            InvitationProjectGrant::query()->create([
                'organization_id' => $organizationId,
                'project_id' => DemoIds::project(ProjectsSeeder::STOREFRONT),
                'invitation_id' => $invitation->id,
                'role' => ProjectRole::Developer,
            ]);
        });
    }
}
