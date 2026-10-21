<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Models\Project;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\ResourceNotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Support\Models\TestWidget;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
    app(TenantContext::class)->set($this->organization->id);
    $this->widget = TestWidget::query()->create(['project_id' => $this->project->id, 'name' => 'Original']);
});

it('updates the record and bumps the version when the expected version matches', function () {
    $version = VersionedUpdate::apply(TestWidget::query()->whereKey($this->widget->id), 1, ['name' => 'Renamed']);

    $fresh = TestWidget::query()->findOrFail($this->widget->id);

    expect($version)->toBe(2)->and($fresh->name)->toBe('Renamed')->and($fresh->version)->toBe(2);
});

it('lets the first of two stale editors win and reports both versions to the second', function () {
    VersionedUpdate::apply(TestWidget::query()->whereKey($this->widget->id), 1, ['name' => 'First editor']);

    try {
        VersionedUpdate::apply(TestWidget::query()->whereKey($this->widget->id), 1, ['name' => 'Second editor']);
        $this->fail('The second stale write should conflict.');
    } catch (VersionConflict $e) {
        expect($e->details())->toBe(['expectedVersion' => 1, 'currentVersion' => 2])
            ->and($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('VERSION_CONFLICT');
    }

    expect(TestWidget::query()->findOrFail($this->widget->id)->name)->toBe('First editor');
});

it('renders the conflict as the error envelope', function () {
    Route::middleware('api')->prefix('api/v1')->get('/_t/conflict', fn () => throw new VersionConflict(17, 18));

    $this->getJson('/api/v1/_t/conflict')->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')
        ->assertJsonPath('error.details.expectedVersion', 17)->assertJsonPath('error.details.currentVersion', 18);
});

it('treats a record in another organization as not found, not as a conflict', function () {
    $other = Organization::factory()->create();
    app(TenantContext::class)->set($other->id);

    VersionedUpdate::apply(TestWidget::query()->whereKey($this->widget->id), 1, ['name' => 'Hijacked']);
})->throws(ResourceNotFound::class);
