<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Api\V1;

use App\Modules\Projects\Queries\ProjectSettings;
use App\Modules\Projects\Queries\TokenProjects;
use App\Shared\Actors\Actor;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The project a token belongs to, with its settings and the defaults new flags start with.
 */
final class ProjectController
{
    /** GET /api/v1/projects/{project} */
    public function show(Request $request, Actor $actor, TokenProjects $projects, ProjectSettings $settings): JsonResponse
    {
        $project = $projects->resolve($request);
        if (! $actor->can(Capability::ProjectRead, new ProjectScope($project->organizationId, $project->id))) {
            throw new AuthorizationDenied;
        }

        $values = $settings->for($project->id);

        return response()->json([
            'id' => PublicId::encode(IdPrefix::Project, $project->id),
            'key' => $values['key'],
            'name' => $values['name'],
            'description' => $values['description'],
            'time_zone' => $values['timeZone'],
            'status' => $project->status,
            'flag_defaults' => [
                'key_format' => $values['flagKeyFormat'],
                'stale_after_days' => $values['staleAfterDays'],
                'require_description' => $values['requireFlagDescription'],
                'require_owner' => $values['requireFlagOwner'],
                'client_visible' => $values['clientVisibleByDefault'],
            ],
        ]);
    }
}
