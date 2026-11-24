<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Models\ProjectExport;
use App\Shared\Tenancy\TenantContext;

/** Marks an abandoned queued or running export as failed so it cannot block the project forever. */
final class FailProjectExport
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(string $organizationId, string $exportId): void
    {
        $this->tenant->run($organizationId, function () use ($exportId): void {
            ProjectExport::query()
                ->whereKey($exportId)
                ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
                ->update(['status' => ExportStatus::Failed, 'error_code' => 'export_failed']);
        });
    }
}
