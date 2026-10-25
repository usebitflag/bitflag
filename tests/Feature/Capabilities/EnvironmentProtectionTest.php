<?php

declare(strict_types=1);

use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Environments\Queries\PolicyEnvironmentProtection;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Actions\CreateProject;
use App\Modules\Projects\Data\CreateProjectData;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\CapabilityGate;
use App\Shared\Capabilities\EnvironmentProtection;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/** A project made through CreateProject, so its environments carry real policies. */
function real_project(): array
{
    $owner = person(OrgRole::Owner, null);
    $result = app(CreateProject::class)->handle($owner['actor'], new CreateProjectData($owner['organization']->id, 'Storefront', 'storefront'));

    $with = function (ProjectRole $role) use ($owner, $result): array {
        $member = person(null, null);
        OrganizationMembership::factory()->create(['organization_id' => $owner['organization']->id, 'user_id' => $member['user']->id, 'role' => OrgRole::Member]);
        ProjectMembership::factory()->create(['organization_id' => $owner['organization']->id, 'project_id' => $result->projectId, 'user_id' => $member['user']->id, 'role' => $role]);

        return ['actor' => Actor::user($member['user']->id, $member['user']->name, Channel::Dashboard), 'user' => $member['user']];
    };

    $scope = fn (string $key): EnvironmentScope => new EnvironmentScope($owner['organization']->id, $result->projectId, $result->environmentIds[$key]);

    return [$owner, $result, $with, $scope];
}

it('treats an environment as protected when its current policy says so', function () {
    [, , , $scope] = real_project();
    $protection = app(EnvironmentProtection::class);

    expect($protection)->toBeInstanceOf(PolicyEnvironmentProtection::class)
        ->and($protection->isProtected($scope('production')))->toBeTrue()
        ->and($protection->isProtected($scope('staging')))->toBeFalse()
        ->and($protection->isProtected($scope('development')))->toBeFalse();
});

it('lets only Owners and Publishers publish a protected environment, and Developers publish the others', function () {
    [$owner, , $with, $scope] = real_project();
    $developer = $with(ProjectRole::Developer)['actor'];
    $publisher = $with(ProjectRole::Publisher)['actor'];
    $viewer = $with(ProjectRole::Viewer)['actor'];

    $canPublish = fn (Actor $actor, string $key): bool => $actor->can(Capability::EnvironmentPublish, $scope($key));

    expect($canPublish($developer, 'staging'))->toBeTrue()
        ->and($canPublish($developer, 'production'))->toBeFalse()
        ->and($canPublish($publisher, 'production'))->toBeTrue()
        ->and($canPublish($owner['actor'], 'production'))->toBeTrue()
        ->and($canPublish($viewer, 'staging'))->toBeFalse()
        ->and($canPublish($viewer, 'production'))->toBeFalse();
});

it('follows the newest version of the policy', function () {
    [$owner, $result, , $scope] = real_project();
    $protection = app(EnvironmentProtection::class);
    expect($protection->isProtected($scope('production')))->toBeTrue();

    Tenancy::crossTenant('next policy version', fn () => EnvironmentPolicy::query()->create([
        'organization_id' => $owner['organization']->id, 'project_id' => $result->projectId, 'environment_id' => $result->environmentIds['production'],
        'version' => 2, 'is_protected' => false, 'require_reason' => false,
    ]));

    // A new request sees the new version; within one request the answer is remembered.
    app()->forgetInstance(EnvironmentProtection::class);

    expect(app(EnvironmentProtection::class)->isProtected($scope('production')))->toBeFalse();
});

it('treats an environment with no policy as unprotected', function () {
    $p = person(OrgRole::Owner, ProjectRole::Owner);

    expect(app(EnvironmentProtection::class)->isProtected(environmentScope($p)))->toBeFalse();
});

it('does not read another organization\'s policy', function () {
    [, , , $scope] = real_project();
    [, , , $otherScope] = real_project();
    $mine = $scope('production');
    $foreign = new EnvironmentScope($mine->organizationId(), $otherScope('production')->projectId, $otherScope('production')->environmentId);

    expect(app(EnvironmentProtection::class)->isProtected($foreign))->toBeFalse();
});

describe('asking the Gate about a capability', function () {
    it('answers for each capability from the same rules as Actor::can', function () {
        $owner = person(OrgRole::Owner, ProjectRole::Owner);
        $member = person(OrgRole::Member, ProjectRole::Viewer);

        $organization = new OrganizationScope($owner['organization']->id);

        expect(Gate::forUser($owner['user'])->allows('org.manage', $organization))->toBeTrue()
            ->and(Gate::forUser($owner['user'])->allows('projects.create', $organization))->toBeTrue()
            ->and(Gate::forUser($member['user'])->allows('org.manage', new OrganizationScope($member['organization']->id)))->toBeFalse()
            ->and(Gate::forUser($member['user'])->allows('org.read', new OrganizationScope($member['organization']->id)))->toBeTrue()
            ->and(Gate::forUser($member['user'])->allows('flags.draft.write', projectScope($member)))->toBeFalse()
            ->and(Gate::forUser($member['user'])->allows('project.read', projectScope($member)))->toBeTrue();
    });

    it('denies a capability in another organization', function () {
        $mine = person(OrgRole::Owner, null);
        $theirs = person(OrgRole::Owner, null);

        expect(Gate::forUser($mine['user'])->allows('org.manage', new OrganizationScope($theirs['organization']->id)))->toBeFalse();
    });

    it('defines one ability for every capability and none for a role', function () {
        CapabilityGate::register();

        foreach (Capability::cases() as $capability) {
            expect(Gate::has($capability->value))->toBeTrue($capability->value);
        }

        expect(Gate::has('owner'))->toBeFalse()->and(Gate::has('org.owner'))->toBeFalse();
    });

    it('turns a refusal into a 403 for the person', function () {
        $member = person(OrgRole::Member, ProjectRole::Viewer);

        expect(fn () => Gate::forUser($member['user'])->authorize('org.manage', new OrganizationScope($member['organization']->id)))
            ->toThrow(AuthorizationException::class);
    });
});
