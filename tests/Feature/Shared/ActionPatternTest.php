<?php

declare(strict_types=1);

use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Idempotency\IdempotencyKeyReused;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Actions\RenameWidget;
use Tests\Support\Actions\RenameWidgetData;
use Tests\Support\Models\TestWidget;

beforeEach(function () {
    app(AuditCatalog::class)->register('widget.renamed');
    $this->owner = person(OrgRole::Member, ProjectRole::Owner);
    $this->widget = app(TenantContext::class)->run($this->owner['organization']->id, fn () => TestWidget::query()->create([
        'project_id' => $this->owner['project']->id,
        'name' => 'Original',
    ]));
    $this->data = fn (array $override = []): RenameWidgetData => new RenameWidgetData(...[
        'organizationId' => $this->owner['organization']->id,
        'projectId' => $this->owner['project']->id,
        'widgetId' => $this->widget->id,
        'expectedVersion' => 1,
        'name' => 'Renamed',
        'idempotencyKey' => 'rename-1',
        ...$override,
    ]);
    $this->state = fn () => Tenancy::crossTenant('inspect', fn () => [
        'widget' => TestWidget::query()->findOrFail($this->widget->id),
        'audit' => AuditEvent::query()->count(),
        'outbox' => OutboxMessageRecord::query()->count(),
    ]);
});

it('writes the change, the audit entry and the outbox message together', function () {
    $outcome = app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)());

    $state = ($this->state)();
    $audit = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->firstOrFail());
    $message = OutboxMessageRecord::query()->firstOrFail();

    expect($outcome->body()['version'])->toBe(2)
        ->and($state['widget']->name)->toBe('Renamed')
        ->and($state['widget']->version)->toBe(2)
        ->and($audit->action)->toBe('widget.renamed')
        ->and($audit->actor_id)->toBe($this->owner['user']->id)
        ->and($audit->project_id)->toBe($this->owner['project']->id)
        ->and($message->type)->toBe('internal.widget_renamed')
        ->and($message->payload)->toBe(['version' => 2])
        ->and($message->aggregate_id)->toBe($this->widget->id);
});

it('rolls the change back when the audit entry cannot be written', function () {
    app()->forgetInstance(AuditCatalog::class);
    app()->singleton(AuditCatalog::class);

    expect(fn () => app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)()))->toThrow(UnknownAuditAction::class);

    $state = ($this->state)();
    expect($state['widget']->name)->toBe('Original')->and($state['widget']->version)->toBe(1)
        ->and($state['audit'])->toBe(0)->and($state['outbox'])->toBe(0);
});

it('rolls the change and the audit entry back when the outbox message cannot be written', function () {
    expect(fn () => app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)(['failOutbox' => true])))->toThrow(InvalidArgumentException::class);

    $state = ($this->state)();
    expect($state['widget']->name)->toBe('Original')->and($state['audit'])->toBe(0)->and($state['outbox'])->toBe(0);
});

it('reports a version conflict and writes nothing', function () {
    app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)());

    try {
        app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)(['name' => 'Stale', 'idempotencyKey' => 'rename-2']));
        $this->fail('A stale edit should conflict.');
    } catch (VersionConflict $e) {
        expect($e->details())->toBe(['expectedVersion' => 1, 'currentVersion' => 2]);
    }

    $state = ($this->state)();
    expect($state['widget']->name)->toBe('Renamed')->and($state['audit'])->toBe(1)->and($state['outbox'])->toBe(1);
});

it('replays a retried request without a second change', function () {
    app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)());
    $retry = app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)());

    $state = ($this->state)();
    expect($retry->replayed)->toBeTrue()->and($state['widget']->version)->toBe(2)
        ->and($state['audit'])->toBe(1)->and($state['outbox'])->toBe(1);
});

it('rejects the same key for a different request', function () {
    app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)());

    app(RenameWidget::class)->handle($this->owner['actor'], ($this->data)(['name' => 'Something else']));
})->throws(IdempotencyKeyReused::class);

it('refuses someone without the capability and writes nothing', function () {
    $viewer = person(OrgRole::Member, ProjectRole::Viewer);
    $data = ($this->data)(['organizationId' => $viewer['organization']->id, 'projectId' => $viewer['project']->id]);

    expect(fn () => app(RenameWidget::class)->handle($viewer['actor'], $data))->toThrow(AuthorizationDenied::class);

    expect(($this->state)()['audit'])->toBe(0);
});

it('does not find a record in another organization', function () {
    $stranger = person(OrgRole::Member, ProjectRole::Owner);
    $data = ($this->data)(['organizationId' => $stranger['organization']->id, 'projectId' => $stranger['project']->id]);

    app(RenameWidget::class)->handle($stranger['actor'], $data);
})->throws(ModelNotFoundException::class);
