<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Organizations\Actions\ChangeOrganizationRole;
use App\Modules\Organizations\Actions\InviteMembers;
use App\Modules\Organizations\Actions\RemoveOrganizationMember;
use App\Modules\Organizations\Actions\ResendInvitation;
use App\Modules\Organizations\Actions\RevokeInvitation;
use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Data\ChangeOrganizationRoleData;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Data\RemoveOrganizationMemberData;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Http\Requests\ChangeOrganizationRoleRequest;
use App\Modules\Organizations\Http\Requests\InviteMembersRequest;
use App\Modules\Organizations\Queries\OrganizationMembers;
use App\Modules\Organizations\Queries\OrganizationProjects;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization members: the list, inviting, sending an invitation again or withdrawing it, changing a role, removing
 * someone. A Member sees only their own row; an Org Owner manages everyone.
 */
final class OrganizationMembersController
{
    /** GET /{organization}/settings/members */
    public function index(Request $request, Actor $actor, OrganizationMembers $members, OrganizationProjects $projects): Response
    {
        $organization = $this->organization($request);
        $scope = new OrganizationScope($organization);
        Gate::authorize(Capability::OrgRead->value, $scope);

        $canManage = Gate::allows(Capability::OrgMembersManage->value, $scope);
        $userId = (string) $actor->id();

        return Inertia::render('organization/Members', [
            'members' => $members->members($organization, $canManage ? null : $userId),
            'invitations' => $canManage ? $members->openInvitations($organization) : [],
            'projects' => $canManage ? array_map(static fn (array $project): array => [
                'id' => PublicId::encode(IdPrefix::Project, $project['id']),
                'name' => $project['name'],
            ], $projects->for($organization, $userId)) : [],
            'canManage' => $canManage,
            'currentUserId' => $userId,
        ]);
    }

    /** POST /{organization}/settings/members/invitations */
    public function invite(InviteMembersRequest $request, Actor $actor, InviteMembers $invite): RedirectResponse
    {
        $organization = $this->organization($request);

        $grants = [];

        foreach ($request->array('projects') as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && is_string($project['role'] ?? null)) {
                try {
                    $grants[] = new InviteGrant(PublicId::decode(IdPrefix::Project, $project['id']), ProjectRole::from($project['role']));
                } catch (ResourceNotFound) {
                    throw ValidationException::withMessages(['projects' => 'One of those projects is not available.']);
                }
            }
        }

        $result = $invite->handle($actor, new InviteMembersData(
            $organization,
            InviteMembersData::emailsFromText($request->string('emails')->toString()),
            OrgRole::from($request->string('org_role')->toString()),
            $grants,
        ));

        if ($result->created === [] && $result->rejected !== []) {
            throw ValidationException::withMessages(['emails' => $this->rejections($result->rejected)]);
        }

        $count = count($result->created);
        $message = $count === 1 ? 'Invitation sent.' : "{$count} invitations sent.";

        if ($result->rejected !== []) {
            return back()->withErrors(['emails' => $this->rejections($result->rejected)])->with('toast', ['message' => $message, 'tone' => 'neutral']);
        }

        return back()->with('toast', ['message' => $message, 'tone' => 'neutral']);
    }

    /** POST /{organization}/settings/members/invitations/{invitation}/resend */
    public function resend(Request $request, Actor $actor, ResendInvitation $resend): RedirectResponse
    {
        try {
            $resend->handle($actor, new ChangeInvitationData($this->organization($request), $this->routeId($request, 'invitation')));
        } catch (InvitationUnavailable $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Invitation sent again.', 'tone' => 'neutral']);
    }

    /** DELETE /{organization}/settings/members/invitations/{invitation} */
    public function revoke(Request $request, Actor $actor, RevokeInvitation $revoke): RedirectResponse
    {
        try {
            $revoke->handle($actor, new ChangeInvitationData($this->organization($request), $this->routeId($request, 'invitation')));
        } catch (InvitationUnavailable $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Invitation withdrawn.', 'tone' => 'neutral']);
    }

    /** PATCH /{organization}/settings/members/{membership} */
    public function changeRole(ChangeOrganizationRoleRequest $request, Actor $actor, ChangeOrganizationRole $change): RedirectResponse
    {
        try {
            $change->handle($actor, new ChangeOrganizationRoleData($this->organization($request), $this->routeId($request, 'membership'), OrgRole::from($request->string('role')->toString())));
        } catch (LastOwner $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Role changed.', 'tone' => 'neutral']);
    }

    /** DELETE /{organization}/settings/members/{membership} */
    public function remove(Request $request, Actor $actor, RemoveOrganizationMember $remove): RedirectResponse
    {
        try {
            $remove->handle($actor, new RemoveOrganizationMemberData($this->organization($request), $this->routeId($request, 'membership')));
        } catch (LastOwner $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Member removed.', 'tone' => 'neutral']);
    }

    /**
     * A route parameter by name. Route parameters reach a controller by position, and the organization comes first, so a
     * method that wants a later one reads it by name instead.
     */
    private function routeId(Request $request, string $name): string
    {
        $value = $request->route($name);
        abort_unless(is_string($value), 404);

        return $value;
    }

    private function organization(Request $request): string
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null, 404);

        return $scope->organization->id;
    }

    /**
     * @param  array<string, string>  $rejected
     */
    private function rejections(array $rejected): string
    {
        return implode(' ', array_map(static fn (string $email, string $reason): string => "{$email}: {$reason}.", array_keys($rejected), array_values($rejected)));
    }
}
