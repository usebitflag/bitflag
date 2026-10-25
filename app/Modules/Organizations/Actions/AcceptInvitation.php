<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Identity\Actions\RegisterInvitedAccount;
use App\Modules\Identity\Data\RegisterInvitedAccountData;
use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Organizations\Data\AcceptInvitationData;
use App\Modules\Organizations\Data\AcceptInvitationResult;
use App\Modules\Organizations\Domain\AllowedDomains;
use App\Modules\Organizations\Domain\Exceptions\DomainNotAllowed;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\Exceptions\InvitationWrongAccount;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Organizations\Queries\InvitationLookup;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Uses an invitation link. The person is either signed in as the address that was invited, or has no account and makes one
 * here (the link proves the mailbox, so the address is confirmed). Signed in as anyone else, nothing happens and nothing
 * is used up. It joins them to the organization (never lowering an Owner) and to each invited project (never changing a
 * role they already have), and it works exactly once even if two requests arrive together.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly InvitationLookup $lookup,
        private readonly PersonLookup $people,
        private readonly RegisterInvitedAccount $register,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws InvitationUnavailable
     * @throws InvitationWrongAccount
     * @throws DomainNotAllowed
     * @throws ValidationException
     */
    public function handle(AcceptInvitationData $data): AcceptInvitationResult
    {
        $found = $this->lookup->byToken($data->token);

        if ($found === null) {
            throw new InvitationUnavailable;
        }

        return $this->tenant->run($found['organizationId'], fn (): AcceptInvitationResult => DB::transaction(
            fn (): AcceptInvitationResult => $this->accept($found['id'], $data),
        ));
    }

    private function accept(string $invitationId, AcceptInvitationData $data): AcceptInvitationResult
    {
        // The row is locked, so a second request waits and then sees it already used.
        $invitation = Invitation::query()->whereKey($invitationId)->lockForUpdate()->firstOrFail();

        if ($invitation->status !== InvitationStatus::Pending || $invitation->expires_at === null || $invitation->expires_at <= $this->clock->now()) {
            throw new InvitationUnavailable;
        }

        $organization = Organization::query()->findOrFail($invitation->organization_id);

        if (! AllowedDomains::permits($organization->allowed_email_domains, $invitation->email)) {
            throw new DomainNotAllowed('Invitations to other domains are blocked.');
        }

        [$userId, $userName, $created] = $this->person($invitation, $data);
        $actor = Actor::user($userId, $userName, Channel::Dashboard);

        $this->joinOrganization($invitation, $userId);
        [$added, $existing] = $this->joinProjects($actor, $invitation, $userId, $userName);

        $invitation->forceFill([
            'status' => InvitationStatus::Accepted,
            'accepted_by' => $userId,
            'accepted_at' => $this->clock->now(),
        ])->save();

        $this->audit->record($actor, 'invitation.accepted', new AuditTarget('invitation', $invitation->id, $invitation->email), [
            'org_role' => $invitation->org_role->value,
            'projects_added' => count($added),
            'projects_already_members' => count($existing),
        ]);
        $this->outbox->push(OutboxMessage::forOrganization($organization->id, 'internal.member.added', 'user', $userId, ['user_id' => $userId]));

        return new AcceptInvitationResult($userId, $organization->id, $organization->slug, $created, $added, $existing);
    }

    /**
     * @return array{0: string, 1: string, 2: bool} the person's id and name, and whether their account was just made
     *
     * @throws InvitationWrongAccount
     * @throws ValidationException
     */
    private function person(Invitation $invitation, AcceptInvitationData $data): array
    {
        if ($data->signedInUserId !== null) {
            $email = $this->people->emailOf($data->signedInUserId);

            if ($email === null || mb_strtolower($email) !== mb_strtolower($invitation->email)) {
                throw new InvitationWrongAccount;
            }

            return [$data->signedInUserId, $this->people->nameOf($data->signedInUserId) ?? '', false];
        }

        $account = $this->register->handle(new RegisterInvitedAccountData($invitation->email, (string) $data->name, (string) $data->password));

        return [$account->id, $account->name, true];
    }

    private function joinOrganization(Invitation $invitation, string $userId): void
    {
        $membership = OrganizationMembership::query()->where('user_id', $userId)->first();

        if ($membership === null) {
            try {
                OrganizationMembership::query()->create([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $userId,
                    'role' => $invitation->org_role,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already a member by another route a moment ago; nothing to add.
            }

            return;
        }

        // Invited as an Owner while only a Member: raise the role. An Owner invited as a Member keeps being an Owner.
        if ($invitation->org_role === OrgRole::Owner && $membership->role !== OrgRole::Owner) {
            $membership->forceFill(['role' => OrgRole::Owner])->save();
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>} projects added to, and projects already belonged to
     */
    private function joinProjects(Actor $actor, Invitation $invitation, string $userId, string $userName): array
    {
        $added = [];
        $existing = [];

        foreach ($invitation->grants()->get() as $grant) {
            if (ProjectMembership::query()->where('project_id', $grant->project_id)->where('user_id', $userId)->exists()) {
                $existing[] = $grant->project_id;

                continue;
            }

            ProjectMembership::query()->create([
                'organization_id' => $invitation->organization_id,
                'project_id' => $grant->project_id,
                'user_id' => $userId,
                'role' => $grant->role,
                'added_by' => $invitation->invited_by,
            ]);

            $this->audit->record($actor, 'project_member.added', new AuditTarget('project_member', $userId, $userName), ['project_id' => $grant->project_id, 'role' => $grant->role->value], projectId: $grant->project_id);
            $added[] = $grant->project_id;
        }

        return [$added, $existing];
    }
}
