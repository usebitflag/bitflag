<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Projects\Actions\ArchiveProject;
use App\Modules\Projects\Actions\RestoreProject;
use App\Modules\Projects\Actions\ScheduleProjectDeletion;
use App\Modules\Projects\Actions\UnarchiveProject;
use App\Modules\Projects\Data\ProjectLifecycleData;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's Danger zone: archive, delete (scheduled, with a grace period) and restore. Anyone who can read the project sees
 * the page; only those who manage it can act.
 */
final class ProjectLifecycleController
{
    /** GET .../settings/danger */
    public function show(Request $request): Response
    {
        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        return Inertia::render('project/settings/Danger', [
            'canManage' => Gate::allows(Capability::ProjectManage->value, $scope),
        ]);
    }

    /** POST .../settings/danger/archive */
    public function archive(Request $request, Actor $actor, ArchiveProject $archive): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $archive->handle($actor, new ProjectLifecycleData($organizationId, $projectId));

        return back()->with('toast', ['message' => 'Project archived.', 'tone' => 'neutral']);
    }

    /** POST .../settings/danger/unarchive */
    public function unarchive(Request $request, Actor $actor, UnarchiveProject $unarchive): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $unarchive->handle($actor, new ProjectLifecycleData($organizationId, $projectId));

        return back()->with('toast', ['message' => 'Project unarchived.', 'tone' => 'neutral']);
    }

    /** POST .../settings/danger/delete */
    public function schedule(Request $request, Actor $actor, ScheduleProjectDeletion $schedule): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $request->validate(['confirm' => ['required', 'string', 'max:40']]);

        $schedule->handle($actor, new ProjectLifecycleData($organizationId, $projectId, $request->string('confirm')->toString()));

        return back()->with('toast', ['message' => 'Deletion scheduled. You can restore the project until it is deleted.', 'tone' => 'neutral']);
    }

    /** POST .../settings/danger/restore */
    public function restore(Request $request, Actor $actor, RestoreProject $restore): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $restore->handle($actor, new ProjectLifecycleData($organizationId, $projectId));

        return back()->with('toast', ['message' => 'Project restored.', 'tone' => 'neutral']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null, 404);

        return [$scope->organization->id, $scope->project->id];
    }
}
