<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\CrossTenantWrite;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextMissing;
use Illuminate\Database\Eloquent\Model;

/**
 * Proves that a tenant model keeps organizations apart. Later milestones call this for every new tenant table.
 *
 * @param  class-string<Model>  $modelClass
 * @param  Closure(Organization): Model  $createFor  Creates one row owned by the given organization.
 */
function assertTenantIsolated(string $modelClass, Closure $createFor): void
{
    $context = app(TenantContext::class);
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $own = $createFor($mine);
    $foreign = $createFor($theirs);

    // Fails closed without a tenant.
    expect(fn () => $modelClass::query()->get())->toThrow(TenantContextMissing::class);

    $context->run($mine->id, function () use ($modelClass, $own, $foreign) {
        $ids = $modelClass::query()->pluck('id')->all();

        expect($ids)->toContain($own->getKey())->not->toContain($foreign->getKey())
            ->and($modelClass::query()->find($foreign->getKey()))->toBeNull()
            ->and($modelClass::query()->whereKey($foreign->getKey())->update(['updated_at' => now()]))->toBe(0)
            ->and($modelClass::query()->whereKey($foreign->getKey())->delete())->toBe(0);

        // A copy of my row pointed at another organization cannot be saved from here.
        $copy = $own->replicate();
        $copy->setAttribute('organization_id', $foreign->getAttribute('organization_id'));
        expect(fn () => $copy->save())->toThrow(CrossTenantWrite::class);
    });

    // The foreign row survived, and crossTenant sees both.
    $all = Tenancy::crossTenant('isolation check', fn () => $modelClass::query()->pluck('id')->all());
    expect($all)->toContain($own->getKey(), $foreign->getKey());
}

it('isolates projects', function () {
    assertTenantIsolated(Project::class, fn (Organization $organization) => Project::factory()->create(['organization_id' => $organization->id]));
});

it('isolates environments', function () {
    assertTenantIsolated(Environment::class, function (Organization $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Environment::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id]);
    });
});

it('isolates organization memberships', function () {
    assertTenantIsolated(OrganizationMembership::class, fn (Organization $organization) => OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory()->create()->id,
    ]));
});

it('isolates project memberships', function () {
    assertTenantIsolated(ProjectMembership::class, function (Organization $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return ProjectMembership::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
        ]);
    });
});
