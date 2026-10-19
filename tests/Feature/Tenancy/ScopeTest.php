<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\CrossTenantWrite;
use App\Shared\Tenancy\RestoreTenantContext;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantAware;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\Route;

it('throws instead of returning rows when no organization is in context', function () {
    Project::query()->get();
})->throws(TenantContextMissing::class);

it('throws when a tenant row is created without an organization', function () {
    Project::query()->create(['name' => 'Storefront', 'key' => 'storefront']);
})->throws(TenantContextMissing::class);

it('fills in the organization from the context on create', function () {
    $organization = Organization::factory()->create();

    $project = app(TenantContext::class)->run($organization->id, fn () => Project::query()->create([
        'name' => 'Storefront',
        'key' => 'storefront',
    ]));

    expect($project->organization_id)->toBe($organization->id);
});

it('refuses to save a row that belongs to another organization', function () {
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $foreign = Project::factory()->create(['organization_id' => $theirs->id]);
    $loaded = Tenancy::crossTenant('load the foreign row', fn () => Project::query()->findOrFail($foreign->id));

    app(TenantContext::class)->run($mine->id, fn () => $loaded->save());
})->throws(CrossTenantWrite::class);

it('allows crossTenant to read every organization', function () {
    Project::factory()->count(2)->create();

    $count = Tenancy::crossTenant('count all projects', fn () => Project::query()->count());

    expect($count)->toBe(2);
});

it('restores the previous context after run, even on failure', function () {
    $context = app(TenantContext::class);
    $context->set('outer');

    try {
        $context->run('inner', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect($context->organizationId())->toBe('outer');
});

it('shares one context per request and starts a clean one for the next', function () {
    app(TenantContext::class)->set('first');

    expect(app(TenantContext::class)->organizationId())->toBe('first');

    app()->forgetScopedInstances();

    expect(app(TenantContext::class)->organizationId())->toBeNull();
});

it('resolves route bindings by public ID inside the organization only', function () {
    [$mine, $theirs] = Organization::factory()->count(2)->create();
    $own = Project::factory()->create(['organization_id' => $mine->id]);
    $foreign = Project::factory()->create(['organization_id' => $theirs->id]);

    Route::middleware('web')->get('/_t/projects/{project}', fn (Project $project) => $project->name);
    app(TenantContext::class)->set($mine->id);

    $this->get("/_t/projects/{$own->public_id}")->assertOk()->assertSee($own->name);
    $this->get("/_t/projects/{$foreign->public_id}")->assertNotFound();
    $this->get('/_t/projects/env_'.$own->id)->assertNotFound();
    $this->get('/_t/projects/garbage')->assertNotFound();
});

describe('the job middleware', function () {
    $job = fn (?string $organizationId): TenantAware => new class($organizationId) implements TenantAware
    {
        public function __construct(private readonly ?string $organizationId) {}

        public function organizationId(): ?string
        {
            return $this->organizationId;
        }
    };

    it('sets the context for the job and clears it afterwards', function () use ($job) {
        $context = app(TenantContext::class);
        $seen = null;

        (new RestoreTenantContext)->handle($job('org-1'), function () use ($context, &$seen) {
            $seen = $context->organizationId();
        });

        expect($seen)->toBe('org-1')->and($context->organizationId())->toBeNull();
    });

    it('clears the context when the job fails', function () use ($job) {
        $context = app(TenantContext::class);

        try {
            (new RestoreTenantContext)->handle($job('org-1'), fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
            // expected
        }

        expect($context->organizationId())->toBeNull();
    });

    it('starts a job without a tenant from a clean context', function () use ($job) {
        $context = app(TenantContext::class);
        $context->set('leftover');
        $seen = 'unset';

        (new RestoreTenantContext)->handle($job(null), function () use ($context, &$seen) {
            $seen = $context->organizationId();
        });

        expect($seen)->toBeNull();
    });
});
