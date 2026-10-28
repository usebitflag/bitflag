<?php

declare(strict_types=1);

use App\Modules\Projects\Data\UpdateProjectSettingsData;

function settings_data(array $p, array $change = []): UpdateProjectSettingsData
{
    return new UpdateProjectSettingsData(...[
        'organizationId' => $p['organization']->id,
        'projectId' => $p['project']->id,
        'name' => 'Storefront',
        'key' => $p['project']->key,
        'description' => null,
        'timeZone' => 'UTC',
        'flagKeyFormat' => 'kebab',
        'staleAfterDays' => 30,
        'requireFlagDescription' => true,
        'requireFlagOwner' => true,
        'clientVisibleByDefault' => false,
        'expectedVersion' => 1,
        ...$change,
    ]);
}
