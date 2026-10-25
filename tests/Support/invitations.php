<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\InviteMembers;
use App\Modules\Organizations\Actions\IssueInvitationLink;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Data\InviteMembersResult;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Tenancy\Tenancy;

/**
 * Invites people to the organization of a test person from `person()`.
 *
 * @param  array{actor: Actor, organization: Organization, project: Project}  $by
 * @param  list<string>  $emails
 * @param  list<InviteGrant>|null  $grants  null grants the person's own project as a Developer
 */
function invite_people(array $by, array $emails, OrgRole $role = OrgRole::Member, ?array $grants = null): InviteMembersResult
{
    $grants ??= [new InviteGrant($by['project']->id, ProjectRole::Developer)];

    return app(InviteMembers::class)->handle($by['actor'], new InviteMembersData($by['organization']->id, $emails, $role, $grants));
}

/**
 * Invites one person and issues the link the email would carry, as the worker does.
 *
 * @return array{token: string, invitation: Invitation}
 */
function invitation_link(array $by, string $email = 'dev@northwind.studio', OrgRole $role = OrgRole::Member, ?array $grants = null): array
{
    $result = invite_people($by, [$email], $role, $grants);
    $id = $result->created[0]['invitationId'];
    $token = app(IssueInvitationLink::class)->handle($by['organization']->id, $id) ?? throw new RuntimeException('No link was issued.');

    return ['token' => $token, 'invitation' => Tenancy::crossTenant('inspect', fn () => Invitation::query()->findOrFail($id))];
}

function invitation_status(string $id): string
{
    return Tenancy::crossTenant('inspect', fn () => Invitation::query()->findOrFail($id)->status->value);
}

function verified_user(string $email, string $password = 'correct horse battery'): User
{
    return User::factory()->create(['email' => $email, 'password' => $password]);
}
