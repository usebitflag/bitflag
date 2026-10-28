<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Organizations\Actions\ChangeProjectRole;
use App\Modules\Organizations\Actions\InviteMembers;
use App\Modules\Organizations\Actions\RemoveProjectMember;
use App\Modules\Organizations\Actions\ResendInvitation;
use App\Modules\Organizations\Actions\RevokeInvitation;
use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Data\ChangeProjectRoleData;
use App\Modules\Organizations\Data\InviteGrant;
use App\Modules\Organizations\Data\InviteMembersData;
use App\Modules\Organizations\Data\RemoveProjectMemberData;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Http\Requests\ChangeProjectRoleRequest;
use App\Modules\Organizations\Queries\ProjectMembers;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's Members tab: the people with their roles, the pending invitations to it, and leaving it. Anyone who can read
 * the project sees the tab; only those who manage its members can change it.
 */
final class ProjectMembersController
{
    /** GET /{organization}/{project}/{environment}/settings/members */
    public function index(Request $request, Actor $actor, ProjectMembers $members): Response
    {
        [$organizationId, $projectId, $projectName] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        $canManage = Gate::allows(Capability::MembersManage->value, $scope);

        return Inertia::render('project/settings/Members', [
            'members' => $members->members($projectId),
            'roleCounts' => $members->roleCounts($projectId),
            'invitations' => $canManage ? $members->openInvitations($projectId) : [],
            'canManage' => $canManage,
            'currentUserId' => (string) $actor->id(),
            'project' => ['id' => PublicId::encode(IdPrefix::Project, $projectId), 'name' => $projectName],
        ]);
    }

    /** POST .../settings/members/invitations */
    public function invite(Request $request, Actor $actor, InviteMembers $invite): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);

        $request->validate([
            'emails' => ['required', 'string', 'max:5000'],
            'projects' => ['sometimes', 'array', 'max:1'],
        ]);

        // A project's Members tab invites to this project only, as an organization Member. The role comes from the form.
        $role = $request->input('projects.0.role');
        $roleValue = is_string($role) ? ProjectRole::tryFrom($role) : null;

        if ($roleValue === null) {
            throw ValidationException::withMessages(['projects' => 'Choose a role.']);
        }

        $result = $invite->handle($actor, new InviteMembersData(
            $organizationId,
            InviteMembersData::emailsFromText($request->string('emails')->toString()),
            OrgRole::Member,
            [new InviteGrant($projectId, $roleValue)],
        ));

        $rejections = implode(' ', array_map(static fn (string $email, string $reason): string => "{$email}: {$reason}.", array_keys($result->rejected), array_values($result->rejected)));

        if ($result->created === [] && $result->rejected !== []) {
            throw ValidationException::withMessages(['emails' => $rejections]);
        }

        $count = count($result->created);
        $toast = ['message' => $count === 1 ? 'Invitation sent.' : "{$count} invitations sent.", 'tone' => 'neutral'];

        return $result->rejected === [] ? back()->with('toast', $toast) : back()->withErrors(['emails' => $rejections])->with('toast', $toast);
    }

    /** POST .../settings/members/invitations/{invitation}/resend */
    public function resend(Request $request, Actor $actor, ResendInvitation $resend): RedirectResponse
    {
        [$organizationId] = $this->project($request);

        try {
            $resend->handle($actor, new ChangeInvitationData($organizationId, $this->routeId($request, 'invitation')));
        } catch (InvitationUnavailable $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Invitation sent again.', 'tone' => 'neutral']);
    }

    /** DELETE .../settings/members/invitations/{invitation} */
    public function revoke(Request $request, Actor $actor, RevokeInvitation $revoke): RedirectResponse
    {
        [$organizationId] = $this->project($request);

        try {
            $revoke->handle($actor, new ChangeInvitationData($organizationId, $this->routeId($request, 'invitation')));
        } catch (InvitationUnavailable $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Invitation withdrawn.', 'tone' => 'neutral']);
    }

    /** PATCH .../settings/members/{membership} */
    public function changeRole(ChangeProjectRoleRequest $request, Actor $actor, ChangeProjectRole $change): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);

        try {
            $change->handle($actor, new ChangeProjectRoleData(
                $organizationId,
                $projectId,
                $this->routeId($request, 'membership'),
                ProjectRole::from($request->string('role')->toString()),
                $request->integer('expected_version'),
            ));
        } catch (LastOwner $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        } catch (VersionConflict) {
            return back()->withErrors(['member' => 'Someone else changed this person. Reload the page and try again.']);
        }

        return back()->with('toast', ['message' => 'Role changed.', 'tone' => 'neutral']);
    }

    /** DELETE .../settings/members/{membership} */
    public function remove(Request $request, Actor $actor, RemoveProjectMember $remove): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);

        try {
            $remove->handle($actor, new RemoveProjectMemberData($organizationId, $projectId, $this->routeId($request, 'membership')));
        } catch (LastOwner $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('toast', ['message' => 'Member removed.', 'tone' => 'neutral']);
    }

    /** DELETE /{organization}/{project}/leave */
    public function leave(Request $request, Actor $actor, ProjectMembers $members, RemoveProjectMember $remove): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $mine = collect($members->members($projectId))->firstWhere('userId', $actor->id());

        abort_if($mine === null, 404);

        try {
            $remove->handle($actor, new RemoveProjectMemberData($organizationId, $projectId, $mine['membershipId']));
        } catch (LastOwner $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return redirect('/')->with('toast', ['message' => 'You left the project.', 'tone' => 'neutral']);
    }

    /**
     * @return array{0: string, 1: string, 2: string} the organization id, the project id and the project's name
     */
    private function project(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null, 404);

        return [$scope->organization->id, $scope->project->id, $scope->project->name];
    }

    private function routeId(Request $request, string $name): string
    {
        $value = $request->route($name);
        abort_unless(is_string($value), 404);

        return $value;
    }
}
