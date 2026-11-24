<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Psr\Clock\ClockInterface;

/**
 * The organizations whose grace period has ended, for the scheduler to purge one by one.
 */
final class DueOrganizationDeletions
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return list<string>
     */
    public function ids(int $limit = 50): array
    {
        return array_values(Organization::query()
            ->where('status', OrganizationStatus::DeletionScheduled->value)
            ->where('deletion_due_at', '<=', $this->clock->now())
            ->orderBy('deletion_due_at')
            ->limit($limit)
            ->get()
            ->map(static fn (Organization $organization): string => $organization->id)
            ->all());
    }
}
