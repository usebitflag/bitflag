<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Actions\CreateProject;
use App\Modules\Projects\Data\CreateProjectData;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\PlanLimitReached;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

function new_project(array $p, array $change = [])
{
    return app(CreateProject::class)->handle($p['actor'], new CreateProjectData(...[
        'organizationId' => $p['organization']->id,
        'name' => 'Storefront',
        'key' => 'storefront',
        ...$change,
    ]));
}

/**
 * How many projects the organization has beyond the one the test helper creates for it.
 */
function projects_created(array $p): int
{
    return inside($p, fn () => Project::query()->where('organization_id', $p['organization']->id)->count()) - 1;
}

function inside(array $p, Closure $callback): mixed
{
    return Tenancy::crossTenant('inspect', $callback);
}

it('creates the project, the three template environments and the creator as Owner', function () {
    $p = person(OrgRole::Owner, null);

    $result = new_project($p, ['description' => 'The shop']);

    $project = inside($p, fn () => Project::query()->findOrFail($result->projectId));
    $environments = inside($p, fn () => Environment::query()->where('project_id', $project->id)->orderBy('position')->get());
    $membership = inside($p, fn () => ProjectMembership::query()->where('project_id', $project->id)->firstOrFail());

    expect($project->key)->toBe('storefront')
        ->and($project->description)->toBe('The shop')
        ->and($environments->pluck('key')->all())->toBe(['development', 'staging', 'production'])
        ->and($environments->pluck('position')->all())->toBe([1, 2, 3])
        ->and($environments->pluck('color')->map->value->all())->toBe(['slate', 'lilac', 'violet'])
        ->and($membership->user_id)->toBe($p['user']->id)
        ->and($membership->role)->toBe(ProjectRole::Owner)
        ->and($membership->added_by)->toBe($p['user']->id)
        ->and($result->environmentIds)->toHaveKeys(['development', 'staging', 'production']);
});

it('writes policy version 1 for each environment, with production protected', function () {
    $p = person(OrgRole::Owner, null);
    $result = new_project($p);

    $byKey = inside($p, fn () => Environment::query()->where('project_id', $result->projectId)->get()->keyBy('key'));

    foreach (['development', 'staging', 'production'] as $key) {
        $policy = inside($p, fn () => EnvironmentPolicy::query()->findOrFail($byKey[$key]->current_policy_id));
        $protected = $key === 'production';

        expect($policy->version)->toBe(1)
            ->and($policy->environment_id)->toBe($byKey[$key]->id)
            ->and($policy->is_protected)->toBe($protected)
            ->and($policy->require_reason)->toBe($protected)
            ->and($policy->required_approvals)->toBe(0)
            ->and($policy->allow_self_approval)->toBeFalse()
            ->and($policy->approval_ttl_hours)->toBeNull();
    }
});

it('uses an edited list of environments, protecting prod and production only', function () {
    $p = person(OrgRole::Owner, null);

    $result = new_project($p, ['environments' => [
        ['name' => 'QA', 'key' => 'QA'],
        ['name' => 'Prod', 'key' => 'prod', 'color' => 'indigo'],
        ['name' => 'Canary', 'key' => 'canary', 'color' => 'not-a-color'],
    ]]);

    $environments = inside($p, fn () => Environment::query()->where('project_id', $result->projectId)->orderBy('position')->get());
    $protected = inside($p, fn () => EnvironmentPolicy::query()->where('project_id', $result->projectId)->where('is_protected', true)->count());

    expect($environments->pluck('key')->all())->toBe(['qa', 'prod', 'canary'])
        ->and($environments->pluck('color')->map->value->all())->toBe(['ink', 'indigo', 'ink'])
        ->and($protected)->toBe(1);
});

it('refuses an invalid list of environments', function (array $list, string $field) {
    $p = person(OrgRole::Owner, null);

    try {
        new_project($p, ['environments' => $list]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$field]);
    }

    expect(projects_created($p))->toBe(0);
})->with([
    'none' => [[], 'environments'],
    'eleven' => [array_map(fn ($n) => ['name' => "E{$n}", 'key' => "e{$n}"], range(1, 11)), 'environments'],
    'empty name' => [[['name' => ' ', 'key' => 'dev']], 'environments.0.name'],
    'long name' => [[['name' => str_repeat('a', 41), 'key' => 'dev']], 'environments.0.name'],
    'bad key' => [[['name' => 'Dev', 'key' => '1dev']], 'environments.0.key'],
    'repeated key' => [[['name' => 'Dev', 'key' => 'dev'], ['name' => 'Dev 2', 'key' => 'DEV']], 'environments.1.key'],
]);

it('refuses a name or key that cannot be used', function (array $change, string $field) {
    $p = person(OrgRole::Owner, null);

    try {
        new_project($p, $change);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$field]);
    }
})->with([
    'empty name' => [['name' => '  '], 'name'],
    'long name' => [['name' => str_repeat('a', 81)], 'name'],
    'bad key' => [['key' => 'Bad Key'], 'key'],
    'reserved key' => [['key' => 'settings'], 'key'],
    'long description' => [['description' => str_repeat('a', 281)], 'description'],
]);

it('refuses a key already used in the organization, but not one used in another', function () {
    $p = person(OrgRole::Owner, null);
    new_project($p);

    expect(fn () => new_project($p))->toThrow(ValidationException::class, 'Already used');

    $other = person(OrgRole::Owner, null);
    expect(new_project($other)->key)->toBe('storefront');
});

it('is for people who may create projects in the organization', function () {
    $member = person(OrgRole::Member, ProjectRole::Owner);
    $stranger = person(OrgRole::Owner, null);

    expect(fn () => new_project($member))->toThrow(AuthorizationDenied::class);
    expect(fn () => app(CreateProject::class)->handle($stranger['actor'], new CreateProjectData($member['organization']->id, 'X', 'storefront')))->toThrow(AuthorizationDenied::class);
    expect(fn () => new_project(['actor' => Actor::system('test'), 'organization' => $member['organization']]))->toThrow(AuthorizationDenied::class);
    expect(projects_created($member))->toBe(0);
});

it('refuses a suspended organization', function () {
    $p = person(OrgRole::Owner, null);
    Organization::query()->whereKey($p['organization']->id)->update(['status' => 'suspended']);

    expect(fn () => new_project($p))->toThrow(AuthorizationDenied::class, 'not active');
});

it('records the project and each environment in the audit trail and the outbox', function () {
    $p = person(OrgRole::Owner, null);
    new_project($p);

    expect(inside($p, fn () => AuditEvent::query()->orderBy('id')->pluck('action')->all()))
        ->toBe(['project.created', 'environment.created', 'environment.created', 'environment.created'])
        ->and(OutboxMessageRecord::query()->orderBy('id')->pluck('type')->all())
        ->toBe(['internal.project.created', 'internal.environment.created', 'internal.environment.created', 'internal.environment.created']);
});

it('checks the plan limits before it writes anything', function () {
    $p = person(OrgRole::Owner, null);
    $calls = new ArrayObject;
    app()->bind(EnforcePlanLimit::class, fn () => new class($calls) implements EnforcePlanLimit
    {
        public function __construct(private ArrayObject $calls) {}

        public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
        {
            $this->calls[] = [$limit, $adding];

            if ($limit === LimitKey::EnvironmentsPerProject) {
                throw new PlanLimitReached($limit);
            }
        }
    });

    expect(fn () => new_project($p))->toThrow(PlanLimitReached::class);
    expect($calls->getArrayCopy())->toBe([[LimitKey::Projects, 1], [LimitKey::EnvironmentsPerProject, 3]])
        ->and(projects_created($p))->toBe(0);
});

it('allows everything while no plan limits exist', function () {
    $p = person(OrgRole::Owner, null);

    foreach (range(1, 4) as $n) {
        new_project($p, ['key' => "project-{$n}", 'name' => "Project {$n}"]);
    }

    expect(projects_created($p))->toBe(4);
});

it('leaves nothing behind when a later write fails', function () {
    $p = person(OrgRole::Owner, null);
    app()->bind(Outbox::class, fn () => throw new RuntimeException('outbox down'));

    expect(fn () => new_project($p))->toThrow(RuntimeException::class);
    // Only the project and environment the test helper made are left; no policy, membership or audit entry was written.
    expect(inside($p, fn () => [Project::query()->count(), Environment::query()->count(), EnvironmentPolicy::query()->count(), ProjectMembership::query()->count(), AuditEvent::query()->count()]))
        ->toBe([1, 1, 0, 0, 0]);
});

it('never lets an environment policy change or disappear', function () {
    $p = person(OrgRole::Owner, null);
    $result = new_project($p);
    $policy = inside($p, fn () => EnvironmentPolicy::query()->where('environment_id', $result->environmentIds['production'])->firstOrFail());

    expect(fn () => inside($p, fn () => $policy->forceFill(['is_protected' => false])->save()))->toThrow(LogicException::class)
        ->and(fn () => inside($p, fn () => $policy->delete()))->toThrow(LogicException::class);
});

it('isolates environment policies by organization', function () {
    assertTenantIsolated(EnvironmentPolicy::class, function (Organization $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id]);

        return EnvironmentPolicy::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'environment_id' => $environment->id,
            'version' => 1, 'is_protected' => false, 'require_reason' => false, 'required_approvals' => 0, 'allow_self_approval' => false,
        ]);
    }, mutable: false);
});

it('keeps one policy version per environment', function () {
    $p = person(OrgRole::Owner, null);
    $result = new_project($p);
    $env = inside($p, fn () => Environment::query()->findOrFail($result->environmentIds['staging']));

    expect(fn () => inside($p, fn () => EnvironmentPolicy::query()->create([
        'organization_id' => $env->organization_id, 'project_id' => $env->project_id, 'environment_id' => $env->id,
        'version' => 1, 'is_protected' => true,
    ])))->toThrow(UniqueConstraintViolationException::class);
});
