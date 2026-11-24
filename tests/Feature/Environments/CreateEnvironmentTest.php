<?php

declare(strict_types=1);

use App\Modules\Environments\Actions\CreateEnvironment;
use App\Modules\Environments\Actions\RecordFirstPublication;
use App\Modules\Environments\Actions\ReorderEnvironments;
use App\Modules\Environments\Data\CreateEnvironmentData;
use App\Modules\Environments\Data\ReorderEnvironmentsData;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\PlanLimitReached;
use App\Shared\Plans\PlanRestrictionGate;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function environment_data(array $p, array $change = []): CreateEnvironmentData
{
    return new CreateEnvironmentData(...[
        'organizationId' => $p['organization']->id,
        'projectId' => $p['project']->id,
        'name' => 'QA',
        'key' => 'qa',
        'color' => 'indigo',
        'afterEnvironmentId' => null,
        'protected' => false,
        ...$change,
    ]);
}

/**
 * @return array<string, int> environment key => position
 */
function positions(array $p): array
{
    return Tenancy::crossTenant('inspect', fn () => Environment::query()->where('project_id', $p['project']->id)->orderBy('position')->pluck('position', 'key')->all());
}

describe('creating an environment', function () {
    it('adds it at the end with version 1 of its policy, an audit entry and an outbox message', function () {
        $p = project_with_three();

        $id = app(CreateEnvironment::class)->handle($p['actor'], environment_data($p, ['name' => '  QA ', 'protected' => true]));

        $environment = Tenancy::crossTenant('inspect', fn () => Environment::query()->findOrFail($id));
        $policy = Tenancy::crossTenant('inspect', fn () => EnvironmentPolicy::query()->where('environment_id', $id)->firstOrFail());

        expect($environment->name)->toBe('QA')
            ->and($environment->key)->toBe('qa')
            ->and($environment->color->value)->toBe('indigo')
            ->and($environment->position)->toBe(4)
            ->and($environment->allowed_origins)->toBe([])
            ->and($environment->current_policy_id)->toBe($policy->id)
            ->and($environment->created_by)->toBe($p['user']->id)
            ->and($policy->version)->toBe(1)
            ->and($policy->is_protected)->toBeTrue()
            ->and($policy->require_reason)->toBeTrue()
            ->and($policy->required_approvals)->toBe(0)
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'environment.created')->where('environment_id', $id)->count()))->toBe(1)
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'internal.environment.created')->count()))->toBeGreaterThanOrEqual(1);
    });

    it('puts it after the chosen environment and renumbers the rest', function () {
        $p = project_with_three();

        app(CreateEnvironment::class)->handle($p['actor'], environment_data($p, ['afterEnvironmentId' => $p['ids']['development']]));

        expect(positions($p))->toBe(['development' => 1, 'qa' => 2, 'staging' => 3, 'production' => 4]);
    });

    it('keeps archived environments after the active ones', function () {
        $p = project_with_three();
        Tenancy::crossTenant('inspect', fn () => Environment::query()->whereKey($p['ids']['staging'])->update(['status' => 'archived']));

        app(CreateEnvironment::class)->handle($p['actor'], environment_data($p));

        expect(positions($p))->toBe(['development' => 1, 'production' => 2, 'qa' => 3, 'staging' => 4]);
    });

    it('is for people who manage environments', function (?ProjectRole $role) {
        $p = person(OrgRole::Member, $role);

        expect(fn () => app(CreateEnvironment::class)->handle($p['actor'], environment_data($p)))->toThrow(AuthorizationDenied::class);
    })->with([
        'publisher' => [ProjectRole::Publisher],
        'developer' => [ProjectRole::Developer],
        'viewer' => [ProjectRole::Viewer],
        'no role' => [null],
    ]);

    it('refuses a bad name, key or color, a key in use and a position outside the project', function (array $change, string $field) {
        $p = project_with_three();
        $other = project_with_three();

        try {
            app(CreateEnvironment::class)->handle($p['actor'], environment_data($p, [...$change, ...($field === 'after' ? ['afterEnvironmentId' => $other['ids']['staging']] : [])]));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }

        expect(positions($p))->toHaveCount(3);
    })->with([
        'empty name' => [['name' => ' '], 'name'],
        'long name' => [['name' => str_repeat('a', 41)], 'name'],
        'bad key' => [['key' => '9lives'], 'key'],
        'key in use' => [['key' => 'staging'], 'key'],
        'unknown color' => [['color' => 'red'], 'color'],
        'another project' => [[], 'after'],
    ]);

    it('checks the plan limit and the read-only gate inside its transaction', function () {
        $p = project_with_three();
        $baseline = DB::transactionLevel();
        $calls = new ArrayObject;

        app()->bind(EnforcePlanLimit::class, fn () => new class($calls) implements EnforcePlanLimit
        {
            public function __construct(private readonly ArrayObject $calls) {}

            public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
            {
                $this->calls[] = ['limit', $limit, $projectId, DB::transactionLevel()];
            }
        });
        app()->bind(PlanRestrictionGate::class, fn () => new class($calls) implements PlanRestrictionGate
        {
            public function __construct(private readonly ArrayObject $calls) {}

            public function assertConfigWritable(string $organizationId, string $projectId, ?string $environmentId = null): void
            {
                $this->calls[] = ['gate', null, $projectId, DB::transactionLevel()];
            }
        });

        app(CreateEnvironment::class)->handle($p['actor'], environment_data($p));

        expect($calls->getArrayCopy())->toBe([
            ['limit', LimitKey::EnvironmentsPerProject, $p['project']->id, $baseline + 1],
            ['gate', null, $p['project']->id, $baseline + 1],
        ]);
    });

    it('creates nothing when the plan limit is reached', function () {
        $p = project_with_three();
        app()->bind(EnforcePlanLimit::class, fn () => new class implements EnforcePlanLimit
        {
            public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
            {
                throw new PlanLimitReached($limit);
            }
        });

        expect(fn () => app(CreateEnvironment::class)->handle($p['actor'], environment_data($p)))->toThrow(PlanLimitReached::class)
            ->and(positions($p))->toHaveCount(3);
    });

    it('rolls everything back when the audit entry cannot be written', function () {
        $p = project_with_three();
        app()->instance(AuditCatalog::class, new AuditCatalog);
        app()->forgetInstance(AuditRecorder::class);

        expect(fn () => app(CreateEnvironment::class)->handle($p['actor'], environment_data($p)))->toThrow(UnknownAuditAction::class)
            ->and(positions($p))->toHaveCount(3)
            ->and(Tenancy::crossTenant('inspect', fn () => EnvironmentPolicy::query()->where('project_id', $p['project']->id)->count()))->toBe(0);
    });
});

describe('reordering environments', function () {
    it('saves the new order and records before and after', function () {
        $p = project_with_three();
        $ids = $p['ids'];

        app(ReorderEnvironments::class)->handle($p['actor'], new ReorderEnvironmentsData($p['organization']->id, $p['project']->id, [$ids['production'], $ids['development'], $ids['staging']]));

        $event = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'environment.reordered')->firstOrFail());

        expect(positions($p))->toBe(['production' => 1, 'development' => 2, 'staging' => 3])
            ->and($event->details['before'])->toBe([$ids['development'], $ids['staging'], $ids['production']])
            ->and($event->details['after'])->toBe([$ids['production'], $ids['development'], $ids['staging']]);
    });

    it('records nothing when the order did not change', function () {
        $p = project_with_three();
        $ids = $p['ids'];

        app(ReorderEnvironments::class)->handle($p['actor'], new ReorderEnvironmentsData($p['organization']->id, $p['project']->id, [$ids['development'], $ids['staging'], $ids['production']]));

        expect(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'environment.reordered')->count()))->toBe(0);
    });

    it('is a version conflict when the list is not exactly the active environments', function (callable $list) {
        $p = project_with_three();
        $ids = $p['ids'];

        expect(fn () => app(ReorderEnvironments::class)->handle($p['actor'], new ReorderEnvironmentsData($p['organization']->id, $p['project']->id, $list($ids))))->toThrow(VersionConflict::class)
            ->and(positions($p))->toBe(['development' => 1, 'staging' => 2, 'production' => 3]);
    })->with([
        'one missing' => [fn (array $ids) => [$ids['staging'], $ids['development']]],
        'an unknown one' => [fn (array $ids) => [$ids['staging'], $ids['development'], '01hzzzzzzzzzzzzzzzzzzzzzzz']],
        'a repeat' => [fn (array $ids) => [$ids['staging'], $ids['staging'], $ids['development']]],
    ]);

    it('is for people who manage environments', function () {
        $p = project_with_three();
        $viewer = person(OrgRole::Member, ProjectRole::Viewer);

        expect(fn () => app(ReorderEnvironments::class)->handle($viewer['actor'], new ReorderEnvironmentsData($p['organization']->id, $p['project']->id, array_values($p['ids']))))->toThrow(AuthorizationDenied::class);
    });
});

describe('the first publication', function () {
    it('is recorded once and fixes the time of the first', function () {
        $p = project_with_three();
        $record = fn () => Tenancy::crossTenant('inspect', fn () => DB::transaction(fn () => app(RecordFirstPublication::class)->handle($p['ids']['production'])));

        $record();
        $first = Tenancy::crossTenant('inspect', fn () => Environment::query()->findOrFail($p['ids']['production']))->first_published_at;
        $this->travel(5)->minutes();
        $record();

        expect($first)->not->toBeNull()
            ->and(Tenancy::crossTenant('inspect', fn () => Environment::query()->findOrFail($p['ids']['production']))->first_published_at->equalTo($first))->toBeTrue();
    });
});
