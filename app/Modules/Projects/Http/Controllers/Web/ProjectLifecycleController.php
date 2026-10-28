<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Environments\Queries\EnvironmentSettingsList;
use App\Modules\Projects\Actions\ArchiveProject;
use App\Modules\Projects\Actions\ExportProject;
use App\Modules\Projects\Actions\RestoreProject;
use App\Modules\Projects\Actions\ScheduleProjectDeletion;
use App\Modules\Projects\Actions\UnarchiveProject;
use App\Modules\Projects\Data\ExportProjectData;
use App\Modules\Projects\Data\ProjectLifecycleData;
use App\Modules\Projects\Queries\ProjectExports;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A project's Danger zone: archive, delete (scheduled, with a grace period) and restore. Anyone who can read the project sees
 * the page; only those who manage it can act.
 */
final class ProjectLifecycleController
{
    /** GET .../settings/danger */
    public function show(Request $request, ProjectExports $exports, EnvironmentSettingsList $environments): Response
    {
        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        return Inertia::render('project/settings/Danger', [
            'canManage' => Gate::allows(Capability::ProjectManage->value, $scope),
            'environmentCount' => $environments->count($projectId),
            'graceDays' => Config::integer('bitflag.lifecycle.project_deletion_grace_days'),
            'exports' => Gate::allows(Capability::ProjectManage->value, $scope)
                ? array_map(fn (array $export): array => [...$export, 'downloadUrl' => $export['status'] === 'ready' ? $this->downloadUrl($request, $export['id']) : null], $exports->recent($projectId))
                : [],
        ]);
    }

    /** POST .../settings/danger/export */
    public function export(Request $request, Actor $actor, ExportProject $export): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $export->handle($actor, new ExportProjectData($organizationId, $projectId));

        return back()->with('toast', ['message' => 'Preparing your export. It will be ready to download shortly.', 'tone' => 'neutral']);
    }

    /** GET .../settings/danger/exports/{export}/download (signed) */
    public function download(Request $request, ProjectExports $exports): StreamedResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        Gate::authorize(Capability::ProjectManage->value, new ProjectScope($organizationId, $projectId));
        $id = $request->route('export');
        abort_unless(is_string($id), 404);

        $path = $exports->downloadable($projectId, $id);
        abort_if($path === null, 404);

        return Storage::disk(Config::string('bitflag.exports.disk'))->download($path, "bitflag-project-{$projectId}-export.json", ['Cache-Control' => 'no-store, private']);
    }

    private function downloadUrl(Request $request, string $exportId): string
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null || $scope->environment === null, 404);

        return URL::temporarySignedRoute('project.settings.danger.exports.download', now()->addMinutes(10), [
            'organization' => $scope->organization->slug,
            'project' => $scope->project->key,
            'environment' => $scope->environment->key,
            'export' => $exportId,
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
