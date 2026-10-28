<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Shared\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;

/**
 * The projects whose grace period has ended, for the scheduler to purge one by one.
 */
final class DueDeletions
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * The IDs of an organization's projects waiting to be deleted. Runs inside the organization's tenant context.
     *
     * @return list<string>
     */
    public function scheduledIn(string $organizationId): array
    {
        return array_values(Project::query()
            ->where('organization_id', $organizationId)
            ->where('status', ProjectStatus::DeletionScheduled->value)
            ->get()
            ->map(static fn (Project $project): string => $project->id)
            ->all());
    }

    /**
     * @return list<array{organizationId: string, projectId: string}>
     */
    public function projects(int $limit = 200): array
    {
        return Tenancy::crossTenant('the scheduler finds projects whose deletion is due in every organization', fn (): array => array_values(Project::query()
            ->where('status', ProjectStatus::DeletionScheduled->value)
            ->where('deletion_due_at', '<=', $this->clock->now())
            ->orderBy('deletion_due_at')
            ->limit($limit)
            ->get()
            ->map(static fn (Project $project): array => ['organizationId' => $project->organization_id, 'projectId' => $project->id])
            ->all()));
    }
}
