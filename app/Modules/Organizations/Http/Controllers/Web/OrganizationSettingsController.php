<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers\Web;

use App\Http\Middleware\ResolveActor;
use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Organizations\Actions\AddSelfToProject;
use App\Modules\Organizations\Actions\CancelOrganizationDeletion;
use App\Modules\Organizations\Actions\ScheduleOrganizationDeletion;
use App\Modules\Organizations\Actions\UpdateOrganization;
use App\Modules\Organizations\Actions\UpdateOrganizationSecurity;
use App\Modules\Organizations\Data\OrganizationDeletionData;
use App\Modules\Organizations\Data\UpdateOrganizationData;
use App\Modules\Organizations\Data\UpdateOrganizationSecurityData;
use App\Modules\Organizations\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Organizations\Http\Requests\UpdateOrganizationSecurityRequest;
use App\Modules\Organizations\Queries\OrganizationMembers;
use App\Modules\Organizations\Queries\OrganizationProjects;
use App\Modules\Organizations\Queries\OrganizationSettings;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization settings: General, Security and Projects. Everyone in the organization can read General and Projects; only an
 * Org Owner can change them.
 */
final class OrganizationSettingsController
{
    /** GET /{organization}/settings */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('organization.settings.general', ['organization' => $this->scope($request)['slug']]);
    }

    /** GET /{organization}/settings/general */
    public function general(Request $request, OrganizationSettings $settings): Response
    {
        $organization = $this->scope($request);
        Gate::authorize(Capability::OrgRead->value, new OrganizationScope($organization['id']));

        return Inertia::render('organization/General', [
            'settings' => $settings->for($organization['id']),
            'canManage' => Gate::allows(Capability::OrgManage->value, new OrganizationScope($organization['id'])),
        ]);
    }

    /** PUT /{organization}/settings/general */
    public function updateGeneral(UpdateOrganizationRequest $request, Actor $actor, UpdateOrganization $update): RedirectResponse
    {
        $organization = $this->scope($request);

        try {
            $update->handle($actor, new UpdateOrganizationData(
                $organization['id'],
                $request->string('name')->toString(),
                $request->string('slug')->toString(),
                $request->integer('version'),
            ));
        } catch (VersionConflict) {
            return back()->withErrors(['version' => 'Someone else changed this. Reload the page and try again.']);
        }

        // The URL name may have changed, so go to the page at its new address.
        return redirect()->route('organization.settings.general', ['organization' => strtolower(trim($request->string('slug')->toString()))])
            ->with('toast', ['message' => 'Organization saved.', 'tone' => 'neutral']);
    }

    /** POST /{organization}/settings/general/delete */
    public function scheduleDeletion(Request $request, Actor $actor, ScheduleOrganizationDeletion $schedule): RedirectResponse
    {
        $organization = $this->scope($request);
        $request->validate(['confirm' => ['required', 'string', 'max:40']]);

        $schedule->handle($actor, new OrganizationDeletionData($organization['id'], $request->string('confirm')->toString()));

        return back()->with('toast', ['message' => 'Deletion scheduled. You can restore the organization until it is deleted.', 'tone' => 'neutral']);
    }

    /** POST /{organization}/settings/general/restore */
    public function cancelDeletion(Request $request, Actor $actor, CancelOrganizationDeletion $cancel): RedirectResponse
    {
        $organization = $this->scope($request);
        $left = $cancel->handle($actor, new OrganizationDeletionData($organization['id']));

        return back()->with('toast', ['message' => $left === [] ? 'Organization restored.' : 'Organization restored. Some projects stayed scheduled because of the plan limit.', 'tone' => 'neutral']);
    }

    /** GET /{organization}/settings/security */
    public function security(Request $request, OrganizationSettings $settings, OrganizationMembers $members): Response
    {
        $organization = $this->scope($request);
        Gate::authorize(Capability::OrgManage->value, new OrganizationScope($organization['id']));

        return Inertia::render('organization/Security', [
            'settings' => $settings->for($organization['id']),
            'membersWithoutTwoFactor' => $members->withoutTwoFactor($organization['id']),
        ]);
    }

    /** PUT /{organization}/settings/security */
    public function updateSecurity(UpdateOrganizationSecurityRequest $request, Actor $actor, UpdateOrganizationSecurity $update): RedirectResponse
    {
        $organization = $this->scope($request);

        $domains = array_values(array_filter($request->array('allowed_email_domains'), 'is_string'));
        $idle = $request->input('idle_timeout_minutes');

        try {
            $update->handle($actor, new UpdateOrganizationSecurityData(
                $organization['id'],
                $request->boolean('require_mfa'),
                $idle === null || $idle === '' ? null : $request->integer('idle_timeout_minutes'),
                $domains,
                $request->integer('version'),
            ));
        } catch (VersionConflict) {
            return back()->withErrors(['version' => 'Someone else changed this. Reload the page and try again.']);
        }

        return back()->with('toast', ['message' => 'Security settings saved.', 'tone' => 'neutral']);
    }

    /** GET /{organization}/settings/projects */
    public function projects(Request $request, OrganizationProjects $projects): Response
    {
        $organization = $this->scope($request);
        Gate::authorize(Capability::OrgRead->value, new OrganizationScope($organization['id']));
        $actor = $request->attributes->get(ResolveActor::ATTRIBUTE);

        $rows = $actor instanceof Actor && $actor->id() !== null ? $projects->for($organization['id'], $actor->id()) : [];

        return Inertia::render('organization/Projects', [
            'projects' => array_map(static fn (array $project): array => [...$project, 'id' => PublicId::encode(IdPrefix::Project, $project['id'])], $rows),
            'canJoin' => Gate::allows(Capability::OrgManage->value, new OrganizationScope($organization['id'])),
        ]);
    }

    /** POST /{organization}/settings/projects/{project}/join */
    public function joinProject(Request $request, Actor $actor, AddSelfToProject $join): RedirectResponse
    {
        $organization = $this->scope($request);
        $project = $request->route('projectId');
        abort_unless(is_string($project), 404);

        $join->handle($actor, $organization['id'], PublicId::decode(IdPrefix::Project, $project));

        return back()->with('toast', ['message' => 'You joined the project as Owner.', 'tone' => 'neutral']);
    }

    /**
     * @return array{id: string, slug: string, name: string}
     */
    private function scope(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null, 404);

        return ['id' => $scope->organization->id, 'slug' => $scope->organization->slug, 'name' => $scope->organization->name];
    }
}
