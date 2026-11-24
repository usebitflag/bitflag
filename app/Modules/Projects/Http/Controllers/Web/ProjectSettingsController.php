<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Projects\Actions\UpdateProjectSettings;
use App\Modules\Projects\Data\UpdateProjectSettingsData;
use App\Modules\Projects\Http\Requests\UpdateProjectSettingsRequest;
use App\Modules\Projects\Queries\ProjectSettings;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's settings. Anyone who can read the project sees the General tab; only those who manage the project can save it.
 */
final class ProjectSettingsController
{
    /** GET /{organization}/{project}/{environment}/settings */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('project.settings.general', $this->routeParameters($request));
    }

    /** GET .../settings/general */
    public function general(Request $request, ProjectSettings $settings): Response
    {
        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        return Inertia::render('project/settings/General', [
            'settings' => $settings->for($projectId),
            'canManage' => Gate::allows(Capability::ProjectManage->value, $scope),
            ...$settings->options(),
        ]);
    }

    /** PUT .../settings/general */
    public function updateGeneral(UpdateProjectSettingsRequest $request, Actor $actor, UpdateProjectSettings $update): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);

        try {
            $update->handle($actor, new UpdateProjectSettingsData(
                $organizationId,
                $projectId,
                $request->string('name')->toString(),
                $request->string('key')->toString(),
                $request->filled('description') ? $request->string('description')->toString() : null,
                $request->string('time_zone')->toString(),
                $request->string('flag_key_format')->toString(),
                $request->integer('stale_after_days'),
                $request->boolean('require_flag_description'),
                $request->boolean('require_flag_owner'),
                $request->boolean('client_visible_by_default'),
                $request->integer('version'),
            ));
        } catch (VersionConflict) {
            return back()->withErrors(['version' => 'Someone else changed this. Reload the page and try again.']);
        }

        // The project key is part of every address, so go to the page at its new address.
        $parameters = [...$this->routeParameters($request), 'project' => strtolower(trim($request->string('key')->toString()))];

        return redirect()->route('project.settings.general', $parameters)
            ->with('toast', ['message' => 'Project settings saved.', 'tone' => 'neutral']);
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

    /**
     * @return array{organization: string, project: string, environment: string}
     */
    private function routeParameters(Request $request): array
    {
        $scope = ResolvePortalScope::scopeOf($request);
        abort_if($scope === null || $scope->project === null || $scope->environment === null, 404);

        return ['organization' => $scope->organization->slug, 'project' => $scope->project->key, 'environment' => $scope->environment->key];
    }
}
