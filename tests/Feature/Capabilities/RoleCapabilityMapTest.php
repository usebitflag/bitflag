<?php

declare(strict_types=1);

use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\OrgRoleCapabilityMap;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\RoleCapabilityMap;

/**
 * The role columns of the capability table in docs/conventions.md, as capability value => roles that hold it.
 *
 * @return array<string, list<ProjectRole>>
 */
function documented_project_capabilities(): array
{
    $lines = file(dirname(__DIR__, 3).'/docs/conventions.md', FILE_IGNORE_NEW_LINES) ?: [];
    $roles = [ProjectRole::Owner, ProjectRole::Publisher, ProjectRole::Developer, ProjectRole::Viewer];
    $table = [];

    foreach ($lines as $line) {
        if (preg_match('/^\|\s*`([a-z.]+)`[^|]*\|(.*)\|\s*$/', $line, $match) !== 1) {
            continue;
        }

        $cells = array_map('trim', explode('|', $match[2]));

        if (count($cells) !== 4) {
            continue;
        }

        $table[$match[1]] = array_values(array_filter($roles, static fn (ProjectRole $role): bool => $cells[array_search($role, $roles, true)] === '✓'));
    }

    return $table;
}

it('matches the documented capability table cell by cell', function () {
    $documented = documented_project_capabilities();

    expect($documented)->not->toBeEmpty();

    foreach ($documented as $value => $holders) {
        $capability = Capability::from($value);

        foreach (ProjectRole::cases() as $role) {
            expect(RoleCapabilityMap::allows($role, $capability))
                ->toBe(in_array($role, $holders, true), "{$role->value} and {$value}");
        }
    }
});

it('documents every project capability the map grants', function () {
    $documented = array_keys(documented_project_capabilities());

    foreach (ProjectRole::cases() as $role) {
        foreach (RoleCapabilityMap::for($role) as $capability) {
            expect($documented)->toContain($capability->value);
        }
    }
});

it('keeps organization capabilities out of the project map and the reverse', function () {
    foreach (ProjectRole::cases() as $role) {
        foreach (RoleCapabilityMap::for($role) as $capability) {
            expect($capability->isOrganizationLevel())->toBeFalse();
        }
    }

    foreach (OrgRole::cases() as $role) {
        foreach (OrgRoleCapabilityMap::for($role) as $capability) {
            expect($capability->isOrganizationLevel())->toBeTrue();
        }
    }
});

it('gives the Org Owner the organization capabilities and the Member only read', function () {
    expect(OrgRoleCapabilityMap::for(OrgRole::Owner))->toEqualCanonicalizing([
        Capability::OrgRead, Capability::OrgManage, Capability::OrgMembersManage, Capability::OrgBillingManage, Capability::ProjectsCreate,
    ])->and(OrgRoleCapabilityMap::for(OrgRole::Member))->toBe([Capability::OrgRead]);
});

it('has a unique value for every capability', function () {
    $values = array_map(static fn (Capability $c): string => $c->value, Capability::cases());

    expect($values)->toBe(array_values(array_unique($values)));
});
