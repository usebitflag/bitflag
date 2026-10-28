<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Identity\Queries\EmailVerification;
use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Data\InviteMembersResult;
use App\Modules\Organizations\Domain\AllowedDomains;
use App\Modules\Organizations\Domain\InvitationRules;
use App\Modules\Organizations\Domain\InvitationStatus;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\InvitationProjectGrant;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Invites people by email to an organization, with a role there and optionally roles in some projects. An Org Owner may
 * invite anyone to anything; someone who manages a project's members may invite people to that project as organization
 * Members. While the inviter has not confirmed their own email the invitations wait ("queued") and are sent when they do.
 * Addresses that cannot be invited are turned down one by one, with the reason; the rest go ahead.
 */
final class InviteMembers
{
    public function __construct(
        private readonly EmailVerification $verification,
        private readonly PersonLookup $people,
        private readonly ProjectLookup $projects,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, InviteMembersData $data): InviteMembersResult
    {
        $inviterId = $actor->id();

        if ($inviterId === null) {
            throw new AuthorizationDenied;
        }

        $this->authorize($actor, $data);

        $emails = array_values(array_unique(array_map(static fn (string $email): string => strtolower(trim($email)), $data->emails)));

        if ($emails === []) {
            throw ValidationException::withMessages(['emails' => 'Enter at least one email address.']);
        }

        if (count($emails) > InvitationRules::MAX_EMAILS_PER_REQUEST) {
            throw ValidationException::withMessages(['emails' => 'Invite up to '.InvitationRules::MAX_EMAILS_PER_REQUEST.' people at a time.']);
        }

        $this->checkProjects($data);

        $queued = ! $this->verification->isVerified($inviterId);

        return $this->tenant->run($data->organizationId, fn (): InviteMembersResult => DB::transaction(
            fn (): InviteMembersResult => $this->invite($actor, $inviterId, $data, $emails, $queued),
        ));
    }

    /**
     * @param  list<string>  $emails
     */
    private function invite(Actor $actor, string $inviterId, InviteMembersData $data, array $emails, bool $queued): InviteMembersResult
    {
        $organization = Organization::query()->findOrFail($data->organizationId);
        $today = Invitation::query()->where('created_at', '>=', $this->clock->now()->modify('-1 day'))->count();
        $created = [];
        $rejected = [];

        foreach ($emails as $email) {
            $reason = $this->rejection($organization, $data, $email, $today + count($created));

            if ($reason !== null) {
                $rejected[$email] = $reason;

                continue;
            }

            $invitation = Invitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'org_role' => $data->orgRole,
                'status' => $queued ? InvitationStatus::Queued : InvitationStatus::Pending,
                'invited_by' => $inviterId,
            ]);

            foreach ($data->grants as $grant) {
                InvitationProjectGrant::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $grant->projectId,
                    'invitation_id' => $invitation->id,
                    'role' => $grant->role,
                ]);
            }

            $this->audit->record($actor, 'invitation.created', new AuditTarget('invitation', $invitation->id, $email), [
                'org_role' => $data->orgRole->value,
                'projects' => count($data->grants),
                'queued' => $queued,
            ]);

            if (! $queued) {
                $this->outbox->push(self::emailRequest($organization->id, $invitation->id));
            }

            $created[] = ['email' => $email, 'invitationId' => $invitation->id, 'queued' => $queued];
        }

        return new InviteMembersResult($created, $rejected);
    }

    private function rejection(Organization $organization, InviteMembersData $data, string $email, int $createdToday): ?string
    {
        if (! InvitationRules::isValidEmail($email)) {
            return InvitationRules::INVALID_EMAIL;
        }

        if (! AllowedDomains::permits($organization->allowed_email_domains, $email)) {
            return InvitationRules::DOMAIN_BLOCKED;
        }

        if ($createdToday >= InvitationRules::MAX_PER_ORGANIZATION_PER_DAY) {
            return InvitationRules::DAILY_LIMIT;
        }

        if (Invitation::query()->where('email', $email)->whereIn('status', [InvitationStatus::Queued->value, InvitationStatus::Pending->value])->exists()) {
            return InvitationRules::ALREADY_INVITED;
        }

        $personId = $this->people->idByEmail($email);

        if ($personId !== null && $this->alreadyHasEverything($organization->id, $personId, $data)) {
            return InvitationRules::ALREADY_MEMBER;
        }

        return null;
    }

    /**
     * A person who already belongs to the organization, at least at the invited role, and to every project invited to.
     */
    private function alreadyHasEverything(string $organizationId, string $personId, InviteMembersData $data): bool
    {
        $membership = OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $personId)->first();

        if ($membership === null || ($data->orgRole === OrgRole::Owner && $membership->role !== OrgRole::Owner)) {
            return false;
        }

        foreach ($data->grants as $grant) {
            if (! ProjectMembership::query()->where('project_id', $grant->projectId)->where('user_id', $personId)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function authorize(Actor $actor, InviteMembersData $data): void
    {
        if ($actor->can(Capability::OrgMembersManage, new OrganizationScope($data->organizationId))) {
            return;
        }

        // Someone who manages a project's members may invite people to it, but only as organization Members.
        if ($data->orgRole !== OrgRole::Member || $data->grants === []) {
            throw new AuthorizationDenied;
        }

        foreach ($data->grants as $grant) {
            if (! $actor->can(Capability::MembersManage, new ProjectScope($data->organizationId, $grant->projectId))) {
                throw new AuthorizationDenied;
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function checkProjects(InviteMembersData $data): void
    {
        $ids = array_map(static fn ($grant): string => $grant->projectId, $data->grants);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['projects' => 'Choose each project once.']);
        }

        $active = array_map(static fn ($project): string => $project->id, $this->projects->activeAmong($data->organizationId, $ids));

        if (array_diff($ids, $active) !== []) {
            throw ValidationException::withMessages(['projects' => 'One of those projects is not available.']);
        }
    }

    /**
     * The request to send an invitation's email. It names the invitation only: the link is made when the email is sent.
     */
    public static function emailRequest(string $organizationId, string $invitationId): OutboxMessage
    {
        return OutboxMessage::forOrganization($organizationId, 'internal.email.requested', 'invitation', $invitationId, [
            'template' => 'invitation',
            'invitation_id' => $invitationId,
        ]);
    }
}
