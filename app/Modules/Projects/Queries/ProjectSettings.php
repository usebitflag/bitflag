<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Domain\FlagKeyFormat;
use App\Modules\Projects\Domain\ProjectSettingsRules;
use App\Modules\Projects\Models\Project;
use DateTimeZone;

/**
 * A project's General settings, with the version a form must send back when it saves. Runs inside the organization's tenant
 * context.
 */
final class ProjectSettings
{
    /**
     * What the form offers for its choices.
     *
     * @return array{timeZones: list<string>, staleAfterOptions: list<int>, flagKeyFormats: list<string>}
     */
    public function options(): array
    {
        return [
            'timeZones' => DateTimeZone::listIdentifiers(),
            'staleAfterOptions' => ProjectSettingsRules::STALE_AFTER_DAYS,
            'flagKeyFormats' => array_map(static fn (FlagKeyFormat $format): string => $format->value, FlagKeyFormat::cases()),
        ];
    }

    /**
     * @return array{name: string, key: string, keyLocked: bool, description: string|null, timeZone: string, flagKeyFormat: string, staleAfterDays: int, requireFlagDescription: bool, requireFlagOwner: bool, clientVisibleByDefault: bool, version: int}
     */
    public function for(string $projectId): array
    {
        $project = Project::query()->findOrFail($projectId);

        return [
            'name' => $project->name,
            'key' => $project->key,
            'keyLocked' => $project->key_locked_at !== null,
            'description' => $project->description,
            'timeZone' => $project->time_zone,
            'flagKeyFormat' => $project->flag_key_format->value,
            'staleAfterDays' => $project->stale_after_days,
            'requireFlagDescription' => $project->require_flag_description,
            'requireFlagOwner' => $project->require_flag_owner,
            'clientVisibleByDefault' => $project->client_visible_by_default,
            'version' => $project->version,
        ];
    }
}
