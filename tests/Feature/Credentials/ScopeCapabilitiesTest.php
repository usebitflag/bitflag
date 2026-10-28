<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Environments\Models\Environment;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\CapabilityResolver;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

function management_actor(array $p, array $scopes, ?string $environmentId = null): Actor
{
    $key = make_credential($p, ['scopes' => $scopes, 'environment_id' => $environmentId ?? $p['environment']->id], CredentialType::Management);

    return Actor::managementToken($key['credential']->id, $p['user']->id, $p['project']->id, Channel::Api);
}

function personal_actor(array $p, array $scopes): Actor
{
    $key = make_credential($p, ['scopes' => $scopes], CredentialType::Personal);

    return Actor::personalToken($p['user']->id, $p['user']->name, $key['credential']->id, Channel::Api);
}

/** Every capability a token could hold, as [capability, scope kind]. */
function every_capability(array $p): array
{
    return collect(Capability::cases())->reject(fn (Capability $capability) => $capability->isOrganizationLevel())->all();
}

describe('what each scope allows', function () {
    it('maps scopes to capabilities and nothing else', function (array $scopes, array $expected) {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $actor = management_actor($p, $scopes);
        $project = new ProjectScope($p['organization']->id, $p['project']->id);
        $environment = new EnvironmentScope($p['organization']->id, $p['project']->id, $p['environment']->id);

        $allowed = collect(every_capability($p))
            ->filter(fn (Capability $capability) => $actor->can($capability, $project) || $actor->can($capability, $environment))
            ->map(fn (Capability $capability) => $capability->value)->sort()->values()->all();

        expect($allowed)->toBe(collect($expected)->sort()->values()->all());
    })->with([
        'flags:read' => [['flags:read'], ['project.read', 'audit.read']],
        'sensitive:read' => [['sensitive:read'], ['sensitive.read']],
        'flags:write' => [['flags:write'], ['flags.draft.write', 'segments.write']],
        'publish (management key: no approving)' => [['publish'], ['changeset.submit', 'environment.publish', 'environment.publish.protected']],
        'everything a management key can hold' => [['flags:read', 'sensitive:read', 'flags:write', 'publish'], ['project.read', 'audit.read', 'sensitive.read', 'flags.draft.write', 'segments.write', 'changeset.submit', 'environment.publish', 'environment.publish.protected']],
    ]);

    it('never lets a token manage the project, members, environments, credentials, integrations or experiments', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $actor = management_actor($p, ['flags:read', 'sensitive:read', 'flags:write', 'publish']);
        $scope = new ProjectScope($p['organization']->id, $p['project']->id);

        foreach ([Capability::ProjectManage, Capability::MembersManage, Capability::EnvironmentManage, Capability::CredentialsManage, Capability::IntegrationsManage, Capability::ExperimentsManage] as $capability) {
            expect($actor->can($capability, $scope))->toBeFalse($capability->value);
        }
    });

    it('lets a management key act on environment data only in its own environment', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $other = Environment::factory()->create(['project_id' => $p['project']->id, 'organization_id' => $p['organization']->id, 'key' => 'other', 'position' => 2]);
        $actor = management_actor($p, ['publish']);

        expect($actor->can(Capability::EnvironmentPublish, new EnvironmentScope($p['organization']->id, $p['project']->id, $p['environment']->id)))->toBeTrue()
            ->and($actor->can(Capability::EnvironmentPublish, new EnvironmentScope($p['organization']->id, $p['project']->id, $other->id)))->toBeFalse();
    });

    it('allows project-level writes from any environment\'s management key', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);

        expect(management_actor($p, ['flags:write'])->can(Capability::FlagsDraftWrite, new ProjectScope($p['organization']->id, $p['project']->id)))->toBeTrue();
    });

    it('reaches only its own project and organization', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $other = person(OrgRole::Member, ProjectRole::Owner);
        $actor = management_actor($p, ['flags:read']);

        expect($actor->can(Capability::ProjectRead, new ProjectScope($other['organization']->id, $other['project']->id)))->toBeFalse()
            ->and($actor->can(Capability::ProjectRead, new ProjectScope($p['organization']->id, $other['project']->id)))->toBeFalse();
    });
});

describe('personal tokens', function () {
    it('narrow the person\'s role: a Viewer with the publish scope gets no publish capability', function () {
        $p = person(OrgRole::Member, ProjectRole::Viewer);
        $actor = personal_actor($p, ['flags:read', 'flags:write', 'publish']);
        $scope = new ProjectScope($p['organization']->id, $p['project']->id);

        expect($actor->can(Capability::ProjectRead, $scope))->toBeTrue()
            ->and($actor->can(Capability::FlagsDraftWrite, $scope))->toBeFalse()
            ->and($actor->can(Capability::EnvironmentPublish, $scope))->toBeFalse()
            ->and($actor->can(Capability::ChangesetSubmit, $scope))->toBeFalse();
    });

    it('follow a role change at once: demoting a Publisher to a Viewer removes write', function () {
        $p = person(OrgRole::Member, ProjectRole::Publisher);
        $actor = personal_actor($p, ['flags:read', 'flags:write']);
        $scope = new ProjectScope($p['organization']->id, $p['project']->id);
        expect($actor->can(Capability::FlagsDraftWrite, $scope))->toBeTrue();

        Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('user_id', $p['user']->id)->update(['role' => ProjectRole::Viewer->value]));
        app(CapabilityResolver::class)->forget();

        expect($actor->can(Capability::FlagsDraftWrite, $scope))->toBeFalse()->and($actor->can(Capability::ProjectRead, $scope))->toBeTrue();
    });

    it('can approve, unlike a management key, when the person may', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $scope = new ProjectScope($p['organization']->id, $p['project']->id);

        expect(personal_actor($p, ['publish'])->can(Capability::ChangesetApprove, $scope))->toBeTrue()
            ->and(management_actor($p, ['publish'])->can(Capability::ChangesetApprove, $scope))->toBeFalse();
    });

    it('reach only the project they are bound to', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $elsewhere = Project::factory()->create(['organization_id' => $p['organization']->id]);
        ProjectMembership::factory()->create(['organization_id' => $p['organization']->id, 'project_id' => $elsewhere->id, 'user_id' => $p['user']->id, 'role' => ProjectRole::Owner]);
        $actor = personal_actor($p, ['flags:read']);

        expect($actor->can(Capability::ProjectRead, new ProjectScope($p['organization']->id, $p['project']->id)))->toBeTrue()
            ->and($actor->can(Capability::ProjectRead, new ProjectScope($p['organization']->id, $elsewhere->id)))->toBeFalse();
    });
});
