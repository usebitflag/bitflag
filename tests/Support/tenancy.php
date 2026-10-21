<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
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
 * @param  bool  $mutable  False for append-only models, whose updates and deletes throw instead of returning 0.
 */
function assertTenantIsolated(string $modelClass, Closure $createFor, bool $mutable = true): void
{
    $context = app(TenantContext::class);
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $own = $createFor($mine);
    $foreign = $createFor($theirs);

    // Fails closed without a tenant.
    expect(fn () => $modelClass::query()->get())->toThrow(TenantContextMissing::class);

    $context->run($mine->id, function () use ($modelClass, $own, $foreign, $mutable) {
        $ids = $modelClass::query()->pluck('id')->all();

        expect($ids)->toContain($own->getKey())->not->toContain($foreign->getKey())
            ->and($modelClass::query()->find($foreign->getKey()))->toBeNull();

        if ($mutable) {
            expect($modelClass::query()->whereKey($foreign->getKey())->update(['organization_id' => $foreign->getAttribute('organization_id')]))->toBe(0)
                ->and($modelClass::query()->whereKey($foreign->getKey())->delete())->toBe(0);
        }

        // A copy of my row pointed at another organization cannot be saved from here.
        $copy = $own->replicate();
        $copy->setAttribute('organization_id', $foreign->getAttribute('organization_id'));
        expect(fn () => $copy->save())->toThrow(CrossTenantWrite::class);
    });

    // The foreign row survived, and crossTenant sees both.
    $all = Tenancy::crossTenant('isolation check', fn () => $modelClass::query()->pluck('id')->all());
    expect($all)->toContain($own->getKey(), $foreign->getKey());
}
