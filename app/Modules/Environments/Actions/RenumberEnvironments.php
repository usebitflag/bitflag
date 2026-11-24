<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Models\Environment;

/**
 * Rewrites a project's environment positions as 1..n: the given active environments in the given order, then the archived
 * ones in their current order. Not an entry point: environment actions call it inside their own transaction.
 */
final class RenumberEnvironments
{
    /**
     * The IDs of the project's active environments in display order, locked for the change that follows.
     *
     * @return list<string>
     */
    public function activeIds(string $projectId): array
    {
        return array_values(Environment::query()
            ->where('project_id', $projectId)
            ->where('status', EnvironmentStatus::Active->value)
            ->orderBy('position')
            ->lockForUpdate()
            ->get()
            ->map(static fn (Environment $environment): string => $environment->id)
            ->all());
    }

    /**
     * @param  list<string>  $activeIdsInOrder
     */
    public function handle(string $projectId, array $activeIdsInOrder): void
    {
        $archived = Environment::query()
            ->where('project_id', $projectId)
            ->where('status', '!=', EnvironmentStatus::Active->value)
            ->orderBy('position')
            ->orderBy('key')
            ->pluck('id')
            ->all();

        foreach ([...$activeIdsInOrder, ...$archived] as $index => $id) {
            Environment::query()->whereKey($id)->where('position', '!=', $index + 1)->update(['position' => $index + 1]);
        }
    }
}
