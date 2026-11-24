<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Tenancy\Tenancy;

/**
 * An owner of a project that has development (1), staging (2) and production (3).
 *
 * @return array<string, mixed>
 */
function project_with_three(): array
{
    $p = person(OrgRole::Member, ProjectRole::Owner);
    $ids = ['development' => $p['environment']->id];
    $p['environment']->forceFill(['name' => 'Development', 'key' => 'development', 'position' => 1])->save();

    foreach (['staging' => 2, 'production' => 3] as $key => $position) {
        $ids[$key] = Environment::factory()->create([
            'project_id' => $p['project']->id,
            'organization_id' => $p['organization']->id,
            'name' => ucfirst($key),
            'key' => $key,
            'position' => $position,
        ])->id;
    }

    return [...$p, 'ids' => $ids];
}

/**
 * @return array<string, int> environment key => position
 */
function positions(array $p): array
{
    return Tenancy::crossTenant('inspect', fn () => Environment::query()->where('project_id', $p['project']->id)->orderBy('position')->pluck('position', 'key')->all());
}
