<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Credentials\Data\ResolvedCredential;
use App\Modules\Projects\Models\Project;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use Illuminate\Http\Request;

/**
 * Finds the project named in a management API address (`prj_…` or the project key). It looks only inside the tenant context
 * and only at the project the token is bound to, so any other project, in this organization or another, is simply not found.
 */
final class TokenProjects
{
    /**
     * @throws ResourceNotFound
     */
    public function resolve(Request $request): ProjectReference
    {
        $value = $request->route('project');
        $credential = $request->attributes->get('credential');

        if (! is_string($value) || ! $credential instanceof ResolvedCredential) {
            throw new ResourceNotFound;
        }

        $project = Project::query()
            ->when(
                PublicId::isValid(IdPrefix::Project, $value),
                fn ($query) => $query->whereKey(PublicId::decode(IdPrefix::Project, $value)),
                fn ($query) => $query->where('key', $value),
            )
            ->whereKey($credential->projectId)
            ->first();

        if ($project === null || $project->status->value === 'deleted') {
            throw new ResourceNotFound;
        }

        return new ProjectReference($project->id, $project->organization_id, $project->status->value);
    }
}
