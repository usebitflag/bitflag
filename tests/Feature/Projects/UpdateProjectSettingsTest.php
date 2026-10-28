<?php

declare(strict_types=1);

use App\Modules\Projects\Actions\LockProjectKey;
use App\Modules\Projects\Actions\UpdateProjectSettings;
use App\Modules\Projects\Data\UpdateProjectSettingsData;
use App\Modules\Projects\Domain\FlagKeyFormat;
use App\Modules\Projects\Models\Project;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function project_now(array $p): Project
{
    return Tenancy::crossTenant('inspect', fn () => Project::query()->findOrFail($p['project']->id));
}

function project_audit(): array
{
    return Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project.updated')->get()->all());
}

describe('saving project settings', function () {
    it('saves every field, bumps the version and records what changed', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        $version = app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, [
            'name' => '  Web Storefront ',
            'key' => 'web-storefront',
            'description' => 'The shop',
            'timeZone' => 'Europe/Berlin',
            'flagKeyFormat' => 'snake',
            'staleAfterDays' => 60,
            'requireFlagDescription' => false,
            'requireFlagOwner' => false,
            'clientVisibleByDefault' => true,
        ]));

        $project = project_now($p);
        $audit = project_audit();

        expect($version)->toBe(2)
            ->and($project->version)->toBe(2)
            ->and($project->name)->toBe('Web Storefront')
            ->and($project->key)->toBe('web-storefront')
            ->and($project->description)->toBe('The shop')
            ->and($project->time_zone)->toBe('Europe/Berlin')
            ->and($project->flag_key_format)->toBe(FlagKeyFormat::Snake)
            ->and($project->stale_after_days)->toBe(60)
            ->and($project->require_flag_description)->toBeFalse()
            ->and($project->require_flag_owner)->toBeFalse()
            ->and($project->client_visible_by_default)->toBeTrue()
            ->and($audit)->toHaveCount(1)
            ->and($audit[0]->details['changed'])->toContain('name', 'key', 'time_zone', 'stale_after_days')
            ->and($audit[0]->details['after']['name'])->toBe('Web Storefront')
            ->and($audit[0]->details['before']['time_zone'])->toBe('UTC');
    });

    it('lists only the fields that changed', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['name' => 'Renamed']));

        expect(project_audit()[0]->details['changed'])->toBe(['name']);
    });

    it('is for project Owners only', function (?ProjectRole $role) {
        $p = person(OrgRole::Member, $role);

        expect(fn () => app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p)))->toThrow(AuthorizationDenied::class)
            ->and(project_now($p)->version)->toBe(1)
            ->and(project_audit())->toBeEmpty();
    })->with([
        'publisher' => [ProjectRole::Publisher],
        'developer' => [ProjectRole::Developer],
        'viewer' => [ProjectRole::Viewer],
        'no role' => [null],
    ]);

    it('does not let an Owner of another organization change the project', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $other = person(OrgRole::Member, ProjectRole::Owner);

        expect(fn () => app(UpdateProjectSettings::class)->handle($other['actor'], settings_data($p)))->toThrow(AuthorizationDenied::class);
    });

    it('reports a version conflict and changes nothing', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['name' => 'First']));

        expect(fn () => app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['name' => 'Second'])))->toThrow(VersionConflict::class)
            ->and(project_now($p)->name)->toBe('First')
            ->and(project_audit())->toHaveCount(1);
    });

    it('rolls the change back when the audit entry cannot be written', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        // An empty catalog makes the recorder refuse the entry, as a failing audit write would.
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['name' => 'Lost'])))->toThrow(UnknownAuditAction::class)
            ->and(project_now($p)->name)->not->toBe('Lost')
            ->and(project_now($p)->version)->toBe(1);
    });

    it('refuses values the form cannot send', function (array $change, string $field) {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        try {
            app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, $change));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }

        expect(project_now($p)->version)->toBe(1);
    })->with([
        'empty name' => [['name' => '  '], 'name'],
        'long name' => [['name' => str_repeat('a', 81)], 'name'],
        'long description' => [['description' => str_repeat('a', 281)], 'description'],
        'unknown time zone' => [['timeZone' => 'Mars/Olympus'], 'time_zone'],
        'stale days' => [['staleAfterDays' => 45], 'stale_after_days'],
        'key format' => [['flagKeyFormat' => 'camel'], 'flag_key_format'],
        'bad key' => [['key' => '1bad'], 'key'],
        'reserved key' => [['key' => 'settings'], 'key'],
    ]);

    it('refuses a key another project in the organization uses', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        Project::factory()->create(['organization_id' => $p['organization']->id, 'key' => 'taken']);

        expect(fn () => app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['key' => 'taken'])))->toThrow(ValidationException::class);
    });
});

describe('the project key lock', function () {
    it('locks on the first call and keeps the first time', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $lock = fn () => Tenancy::crossTenant('inspect', fn () => DB::transaction(fn () => app(LockProjectKey::class)->handle($p['project']->id)));

        $lock();
        $first = project_now($p)->key_locked_at;
        $this->travel(5)->minutes();
        $lock();

        expect($first)->not->toBeNull()
            ->and(project_now($p)->key_locked_at->equalTo($first))->toBeTrue();
    });

    it('stops the key from changing but not the other settings', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        Tenancy::crossTenant('inspect', fn () => DB::transaction(fn () => app(LockProjectKey::class)->handle($p['project']->id)));

        expect(fn () => app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['key' => 'new-key'])))->toThrow(ValidationException::class);

        app(UpdateProjectSettings::class)->handle($p['actor'], settings_data($p, ['name' => 'Still editable']));

        expect(project_now($p)->name)->toBe('Still editable')->and(project_now($p)->key)->toBe($p['project']->key);
    });
});
