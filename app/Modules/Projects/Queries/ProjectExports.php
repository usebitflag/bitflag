<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Models\ProjectExport;
use App\Shared\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;

/**
 * A project's recent exports for the Danger zone, one export by ID for the download, and the ones the scheduler must expire.
 */
final class ProjectExports
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return list<array{id: string, status: string, bytes: int|null, createdAt: int, expiresAt: int|null}>
     */
    public function recent(string $projectId, int $limit = 5): array
    {
        return array_values(ProjectExport::query()
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (ProjectExport $export): array => [
                'id' => $export->id,
                'status' => $export->status->value,
                'bytes' => $export->bytes,
                'createdAt' => $export->created_at->getTimestamp(),
                'expiresAt' => $export->expires_at?->getTimestamp(),
            ])
            ->all());
    }

    /**
     * The path of a ready export of the project that has not expired, or null.
     */
    public function downloadable(string $projectId, string $exportId): ?string
    {
        $export = ProjectExport::query()->where('project_id', $projectId)->find($exportId);

        if ($export === null || $export->status !== ExportStatus::Ready || $export->file_path === null || $export->expires_at === null || $export->expires_at <= $this->clock->now()) {
            return null;
        }

        return $export->file_path;
    }

    /**
     * @return list<array{organizationId: string, exportId: string}>
     */
    public function due(int $limit = 200): array
    {
        return Tenancy::crossTenant('the scheduler finds expired exports in every organization', fn (): array => array_values(ProjectExport::query()
            ->where('status', ExportStatus::Ready->value)
            ->where('expires_at', '<=', $this->clock->now())
            ->limit($limit)
            ->get()
            ->map(static fn (ProjectExport $export): array => ['organizationId' => $export->organization_id, 'exportId' => $export->id])
            ->all()));
    }
}
