<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\CapabilityResolver;
use App\Shared\Capabilities\EnvironmentProtection;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Capabilities\Scope;
use App\Shared\Capabilities\TokenScopeMap;
use App\Shared\Tenancy\Tenancy;

it('lets a project member do what their role allows and nothing else', function (ProjectRole $role, Capability $capability, bool $expected) {
    $p = person(OrgRole::Member, $role);

    expect($p['actor']->can($capability, projectScope($p)))->toBe($expected);
})->with([
    'viewer reads' => [ProjectRole::Viewer, Capability::ProjectRead, true],
    'viewer cannot edit drafts' => [ProjectRole::Viewer, Capability::FlagsDraftWrite, false],
    'developer edits drafts' => [ProjectRole::Developer, Capability::FlagsDraftWrite, true],
    'developer cannot approve' => [ProjectRole::Developer, Capability::ChangesetApprove, false],
    'publisher approves' => [ProjectRole::Publisher, Capability::ChangesetApprove, true],
    'publisher cannot manage the project' => [ProjectRole::Publisher, Capability::ProjectManage, false],
    'owner manages the project' => [ProjectRole::Owner, Capability::ProjectManage, true],
]);

it('gives an Org Owner who is not a project member no project capability', function () {
    $p = person(OrgRole::Owner, null);

    foreach ([Capability::ProjectRead, Capability::FlagsDraftWrite, Capability::ProjectManage, Capability::AuditRead] as $capability) {
        expect($p['actor']->can($capability, projectScope($p)))->toBeFalse($capability->value);
    }

    expect($p['actor']->can(Capability::OrgManage, new OrganizationScope($p['organization']->id)))->toBeTrue();
});

it('gives a Member the organization read capability only', function () {
    $p = person(OrgRole::Member, null);
    $scope = new OrganizationScope($p['organization']->id);

    expect($p['actor']->can(Capability::OrgRead, $scope))->toBeTrue()
        ->and($p['actor']->can(Capability::OrgManage, $scope))->toBeFalse()
        ->and($p['actor']->can(Capability::ProjectsCreate, $scope))->toBeFalse();
});

it('does not apply a project capability to an organization-only scope', function () {
    $p = person(OrgRole::Member, ProjectRole::Owner);

    expect($p['actor']->can(Capability::ProjectManage, new OrganizationScope($p['organization']->id)))->toBeFalse();
});

it('denies everything in another organization', function () {
    $mine = person(OrgRole::Owner, ProjectRole::Owner);
    $theirs = person(OrgRole::Owner, ProjectRole::Owner);

    expect($mine['actor']->can(Capability::ProjectManage, projectScope($theirs)))->toBeFalse()
        ->and($mine['actor']->can(Capability::OrgManage, new OrganizationScope($theirs['organization']->id)))->toBeFalse();
});

it('denies a project of the same organization where the person is not a member', function () {
    $p = person(OrgRole::Member, ProjectRole::Owner);
    $other = Project::factory()->create(['organization_id' => $p['organization']->id]);

    expect($p['actor']->can(Capability::ProjectRead, new ProjectScope($p['organization']->id, $other->id)))->toBeFalse();
});

it('denies a deactivated account everything', function () {
    $p = person(OrgRole::Owner, ProjectRole::Owner);
    $p['user']->forceFill(['status' => 'deactivated'])->save();

    expect($p['actor']->can(Capability::ProjectRead, projectScope($p)))->toBeFalse();
});

it('requires the protected-publish capability on a protected environment', function () {
    app()->bind(EnvironmentProtection::class, fn () => new class implements EnvironmentProtection
    {
        public function isProtected(EnvironmentScope $environment): bool
        {
            return true;
        }
    });

    $developer = person(OrgRole::Member, ProjectRole::Developer);
    $publisher = person(OrgRole::Member, ProjectRole::Publisher);

    expect($developer['actor']->can(Capability::EnvironmentPublish, environmentScope($developer)))->toBeFalse()
        ->and($publisher['actor']->can(Capability::EnvironmentPublish, environmentScope($publisher)))->toBeTrue();
});

it('lets a developer publish an unprotected environment', function () {
    $developer = person(OrgRole::Member, ProjectRole::Developer);

    expect($developer['actor']->can(Capability::EnvironmentPublish, environmentScope($developer)))->toBeTrue()
        ->and($developer['actor']->can(Capability::EnvironmentPublishProtected, environmentScope($developer)))->toBeFalse();
});

it('remembers memberships for the request and forgets them on demand', function () {
    $p = person(OrgRole::Member, ProjectRole::Viewer);
    $scope = projectScope($p);

    expect($p['actor']->can(Capability::FlagsDraftWrite, $scope))->toBeFalse();

    Tenancy::crossTenant('promote the member behind the resolver\'s back', fn () => ProjectMembership::query()
        ->where('user_id', $p['user']->id)
        ->where('organization_id', $p['organization']->id)
        ->update(['role' => ProjectRole::Developer->value]));
    expect($p['actor']->can(Capability::FlagsDraftWrite, $scope))->toBeFalse();

    app()->forgetScopedInstances();
    expect($p['actor']->can(Capability::FlagsDraftWrite, $scope))->toBeTrue();
});

describe('other kinds of actor', function () {
    it('lets the system do anything', function () {
        $p = person(null, null);

        expect(Actor::system('outbox:work')->can(Capability::ProjectManage, projectScope($p)))->toBeTrue();
    });

    it('gives support staff no customer capability', function () {
        $p = person(null, null);

        expect(Actor::support('stf_1', 'Support', 'T-1')->can(Capability::ProjectRead, projectScope($p)))->toBeFalse();
    });

    it('gives tokens nothing until their scopes are defined', function () {
        $p = person(OrgRole::Member, ProjectRole::Owner);
        $personal = Actor::personalToken($p['user']->id, $p['user']->name, 'tok_1', Channel::Api);
        $management = Actor::managementToken('tok_2', $p['user']->id, $p['project']->id, Channel::Api);

        expect($personal->can(Capability::ProjectRead, projectScope($p)))->toBeFalse()
            ->and($management->can(Capability::ProjectRead, projectScope($p)))->toBeFalse();
    });

    it('lets a personal token act only where both the person and the token allow', function () {
        app()->bind(TokenScopeMap::class, fn () => new class implements TokenScopeMap
        {
            public function allows(Actor $actor, Capability $capability, Scope $scope): bool
            {
                return $capability === Capability::FlagsDraftWrite;
            }
        });

        $p = person(OrgRole::Member, ProjectRole::Viewer);
        $token = Actor::personalToken($p['user']->id, $p['user']->name, 'tok_1', Channel::Api);

        // The token would allow the write, but the person is only a viewer.
        expect($token->can(Capability::FlagsDraftWrite, projectScope($p)))->toBeFalse();

        $developer = person(OrgRole::Member, ProjectRole::Developer);
        $developerToken = Actor::personalToken($developer['user']->id, $developer['user']->name, 'tok_2', Channel::Api);

        expect($developerToken->can(Capability::FlagsDraftWrite, projectScope($developer)))->toBeTrue()
            ->and($developerToken->can(Capability::SegmentsWrite, projectScope($developer)))->toBeFalse();
    });
});

it('resolves through the container binding', function () {
    expect(app(CapabilityResolver::class))->toBe(app(CapabilityResolver::class));
});
