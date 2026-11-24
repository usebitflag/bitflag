<?php

declare(strict_types=1);

namespace App\Modules\Environments\Http\Controllers\Api\V1;

use App\Modules\Environments\Queries\EnvironmentSettingsList;
use App\Modules\Projects\Queries\TokenProjects;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A project's environments for management API clients: key, name, color, protection summary and policy version.
 *
 * @phpstan-import-type EnvironmentRow from EnvironmentSettingsList
 */
final class EnvironmentController
{
    /** GET /api/v1/projects/{project}/environments */
    public function index(Request $request, Actor $actor, TokenProjects $projects, EnvironmentSettingsList $environments): JsonResponse
    {
        $project = $projects->resolve($request);
        if (! $actor->can(Capability::ProjectRead, new ProjectScope($project->organizationId, $project->id))) {
            throw new AuthorizationDenied;
        }

        return response()->json(['data' => array_map(fn (array $row): array => $this->present($row), $environments->for($project->id))]);
    }

    /** GET /api/v1/projects/{project}/environments/{environment} */
    public function show(Request $request, Actor $actor, TokenProjects $projects, EnvironmentSettingsList $environments): JsonResponse
    {
        $project = $projects->resolve($request);
        if (! $actor->can(Capability::ProjectRead, new ProjectScope($project->organizationId, $project->id))) {
            throw new AuthorizationDenied;
        }

        $value = $request->route('environment');
        abort_unless(is_string($value), 404);

        $row = collect($environments->for($project->id))->first(fn (array $candidate): bool => PublicId::isValid(IdPrefix::Environment, $value)
            ? $candidate['id'] === PublicId::decode(IdPrefix::Environment, $value)
            : $candidate['key'] === $value);

        if ($row === null) {
            throw new ResourceNotFound;
        }

        return response()->json($this->present($row));
    }

    /**
     * @param  EnvironmentRow  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => PublicId::encode(IdPrefix::Environment, $row['id']),
            'key' => $row['key'],
            'name' => $row['name'],
            'color' => $row['color'],
            'status' => $row['status'],
            'protection' => ['protected' => $row['isProtected'], 'required_approvals' => $row['requiredApprovals'], 'policy_version' => $row['policyVersion']],
        ];
    }
}
