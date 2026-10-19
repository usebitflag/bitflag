<?php

declare(strict_types=1);

// A model that carries organization_id must use BelongsToOrganization, or a forgotten scope would leak rows between
// organizations. The tenant table test checks the same rule against the database; this one reads the model source.

/**
 * @return list<string>
 */
function tenant_models_without_scope(string $root): array
{
    $files = glob($root.'/app/Modules/*/Models/*.php') ?: [];
    $missing = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $hasOrganizationColumn = preg_match('/\$organization_id\b|[\'"]organization_id[\'"]/', $source) === 1;

        if ($hasOrganizationColumn && ! str_contains($source, 'BelongsToOrganization')) {
            $missing[] = ltrim(substr($file, strlen($root)), '/');
        }
    }

    sort($missing);

    return $missing;
}

it('scopes every model that has an organization_id', function () {
    expect(tenant_models_without_scope(dirname(__DIR__, 2)))->toBe([]);
})->group('arch');
