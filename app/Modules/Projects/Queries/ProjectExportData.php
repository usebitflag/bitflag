<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Models\Project;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Lifecycle\ProjectExportSection;

/**
 * The `project` section of an export: the project's name, key, description, time zone and the defaults new flags start with.
 */
final class ProjectExportData implements ProjectExportSection
{
    public function name(): string
    {
        return 'project';
    }

    public function export(string $organizationId, string $projectId): array
    {
        $project = Project::query()->findOrFail($projectId);

        return [
            'id' => PublicId::encode(IdPrefix::Project, $project->id),
            'key' => $project->key,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'time_zone' => $project->time_zone,
            'flag_defaults' => [
                'key_format' => $project->flag_key_format->value,
                'stale_after_days' => $project->stale_after_days,
                'require_description' => $project->require_flag_description,
                'require_owner' => $project->require_flag_owner,
                'client_visible' => $project->client_visible_by_default,
            ],
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }
}
