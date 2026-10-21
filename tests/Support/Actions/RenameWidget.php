<?php

declare(strict_types=1);

namespace Tests\Support\Actions;

use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Idempotency\Idempotency;
use App\Shared\Idempotency\IdempotencyOutcome;
use App\Shared\Idempotency\IdempotencyScope;
use App\Shared\Idempotency\OperationResult;
use App\Shared\Idempotency\RequestFingerprint;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Models\TestWidget;

/**
 * The reference implementation of the action pattern, used only by tests:
 * authorize, load tenant-scoped state, check the version, then one transaction that writes the change, the audit entry
 * and the outbox message together.
 */
final class RenameWidget
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly Idempotency $idempotency,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Actor $actor, RenameWidgetData $data): IdempotencyOutcome
    {
        // 1. Authorize.
        if (! $actor->can(Capability::ProjectManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $scope = new IdempotencyScope($data->organizationId, (string) $actor->publicId(), 'rename_widget');
        $fingerprint = RequestFingerprint::of([
            'widget' => $data->widgetId,
            'expected_version' => $data->expectedVersion,
            'name' => $data->name,
        ]);

        return $this->tenant->run($data->organizationId, fn (): IdempotencyOutcome => DB::transaction(
            // 2 to 4. Load, compare the version, write, audit and queue the notice in one commit.
            fn (): IdempotencyOutcome => $this->idempotency->run($scope, $data->idempotencyKey, $fingerprint, function () use ($actor, $data): OperationResult {
                $widget = TestWidget::query()->findOrFail($data->widgetId);
                $newVersion = VersionedUpdate::apply(TestWidget::query()->whereKey($widget->id), $data->expectedVersion, ['name' => $data->name]);

                $this->audit->record($actor, 'widget.renamed', new AuditTarget('widget', $widget->id, $data->name), ['version' => $newVersion], projectId: $widget->project_id);

                $this->outbox->push(OutboxMessage::forOrganization(
                    $data->organizationId,
                    'internal.widget_renamed',
                    'widget',
                    $widget->id,
                    $data->failOutbox ? ['api_token' => 'x'] : ['version' => $newVersion],
                    projectId: $widget->project_id,
                ));

                return new OperationResult(200, ['id' => $widget->id, 'name' => $data->name, 'version' => $newVersion], 'widget', $widget->id);
            }),
        ));
    }
}
