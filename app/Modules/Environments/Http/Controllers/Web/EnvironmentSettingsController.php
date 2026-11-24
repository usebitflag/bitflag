<?php

declare(strict_types=1);

namespace App\Modules\Environments\Http\Controllers\Web;

use App\Http\Middleware\ResolvePortalScope;
use App\Modules\Environments\Actions\CreateEnvironment;
use App\Modules\Environments\Actions\ReorderEnvironments;
use App\Modules\Environments\Actions\UpdateEnvironmentSettings;
use App\Modules\Environments\Data\CreateEnvironmentData;
use App\Modules\Environments\Data\ReorderEnvironmentsData;
use App\Modules\Environments\Data\UpdateEnvironmentSettingsData;
use App\Modules\Environments\Http\Requests\CreateEnvironmentRequest;
use App\Modules\Environments\Http\Requests\UpdateEnvironmentSettingsRequest;
use App\Modules\Environments\Queries\EnvironmentHistoryQuery;
use App\Modules\Environments\Queries\EnvironmentSettingsList;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Plans\Entitlements;
use App\Shared\Plans\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-import-type EnvironmentRow from EnvironmentSettingsList
 *
 * A project's environments in its settings: the list with ordering and creation, and one environment's tabs. Anyone who can
 * read the project sees them; only those who manage environments can change them.
 */
final class EnvironmentSettingsController
{
    private const TABS = ['overview', 'delivery', 'history', 'settings'];

    /** GET .../settings/environments */
    public function index(Request $request, EnvironmentSettingsList $environments): Response
    {
        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        return Inertia::render('project/settings/environments/Index', [
            'environments' => array_map(fn (array $row): array => $this->display($row), $environments->for($projectId)),
            'canManage' => Gate::allows(Capability::EnvironmentManage->value, $scope),
            'colors' => ['slate', 'lilac', 'violet', 'indigo', 'ink'],
        ]);
    }

    /** POST .../settings/environments */
    public function store(CreateEnvironmentRequest $request, Actor $actor, CreateEnvironment $create): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $after = $request->input('after');

        $id = $create->handle($actor, new CreateEnvironmentData(
            $organizationId,
            $projectId,
            $request->string('name')->toString(),
            $request->string('key')->toString(),
            $request->string('color')->toString(),
            is_string($after) && $after !== '' ? PublicId::decode(IdPrefix::Environment, $after) : null,
            $request->boolean('protected'),
        ));

        return redirect()->route('project.settings.environments.show', [...$this->routeParameters($request), 'target' => strtolower(trim($request->string('key')->toString()))])
            ->with('toast', ['message' => 'Environment created.', 'tone' => 'neutral']);
    }

    /** PUT .../settings/environments/order */
    public function reorder(Request $request, Actor $actor, ReorderEnvironments $reorder): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $request->validate(['order' => ['required', 'array', 'max:50'], 'order.*' => ['string', 'max:40']]);

        $ids = array_map(
            static fn (mixed $id): string => PublicId::decode(IdPrefix::Environment, is_string($id) ? $id : ''),
            array_values($request->array('order')),
        );

        try {
            $reorder->handle($actor, new ReorderEnvironmentsData($organizationId, $projectId, $ids));
        } catch (VersionConflict) {
            return back()->withErrors(['order' => 'The environments changed. The list was reloaded.']);
        }

        return back();
    }

    /** GET .../settings/environments/{target}/{tab?} */
    public function show(Request $request, EnvironmentSettingsList $environments, EnvironmentHistoryQuery $history, Entitlements $entitlements): Response
    {
        // Read by name: route parameters reach controller arguments in route order, and the portal scope comes first.
        $target = $request->route('target');
        $tab = $request->route('tab') ?? 'overview';
        abort_unless(is_string($target) && is_string($tab), 404);

        [$organizationId, $projectId] = $this->project($request);
        $scope = new ProjectScope($organizationId, $projectId);
        Gate::authorize(Capability::ProjectRead->value, $scope);

        abort_unless(in_array($tab, self::TABS, true), 404);
        $row = $environments->byKey($projectId, $target);
        abort_if($row === null, 404);

        return Inertia::render('project/settings/environments/Show', [
            'environment' => $this->display($row),
            'tab' => $tab,
            'history' => $tab === 'history' ? $history->for($row['id']) : [],
            'canManage' => Gate::allows(Capability::EnvironmentManage->value, $scope),
            'approvalsAvailable' => $entitlements->allows($organizationId, Feature::RequiredApprovals),
            'snapshotAges' => UpdateEnvironmentSettings::SNAPSHOT_AGES,
            'colors' => ['slate', 'lilac', 'violet', 'indigo', 'ink'],
        ]);
    }

    /** PUT .../settings/environments/{target}/settings */
    public function update(UpdateEnvironmentSettingsRequest $request, Actor $actor, UpdateEnvironmentSettings $update, EnvironmentSettingsList $environments): RedirectResponse
    {
        [$organizationId, $projectId] = $this->project($request);
        $target = $request->route('target');
        abort_unless(is_string($target), 404);
        $row = $environments->byKey($projectId, $target);
        abort_if($row === null, 404);

        $age = $request->input('max_snapshot_age');
        $origins = array_values(array_filter($request->array('allowed_origins'), 'is_string'));

        try {
            $update->handle($actor, new UpdateEnvironmentSettingsData(
                $organizationId,
                $projectId,
                $row['id'],
                $request->string('name')->toString(),
                $request->string('key')->toString(),
                $request->string('color')->toString(),
                $origins,
                $age === null || $age === '' ? null : $request->integer('max_snapshot_age'),
                $request->boolean('protected'),
                $request->integer('required_approvals'),
                $request->integer('version'),
            ));
        } catch (VersionConflict) {
            return back()->withErrors(['version' => 'Someone else changed this. Reload the page and try again.']);
        }

        // The key is part of the address, so go to the page at its new address.
        return redirect()->route('project.settings.environments.show', [...$this->routeParameters($request), 'target' => strtolower(trim($request->string('key')->toString())), 'tab' => 'settings'])
            ->with('toast', ['message' => 'Environment saved.', 'tone' => 'neutral']);
    }

    /**
     * @param  EnvironmentRow  $row
     * @return EnvironmentRow
     */
    private function display(array $row): array
    {
        return [...$row, 'id' => PublicId::encode(IdPrefix::Environment, $row['id'])];
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
