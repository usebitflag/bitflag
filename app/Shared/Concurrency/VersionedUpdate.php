<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Shared\Errors\ResourceNotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Optimistic concurrency for editable records. A caller sends the version it read; the update succeeds only if the row
 * still has that version, and bumps it. Otherwise VersionConflict reports both versions so the portal can offer a
 * compare-and-reapply flow instead of silently overwriting someone else's edit.
 *
 *   UPDATE … SET …, version = version + 1 WHERE id = ? AND organization_id = ? AND version = ?
 *
 * The query must already target one record. Tenant models add the organization predicate through their scope.
 */
final class VersionedUpdate
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  Selects exactly one record.
     * @param  array<string, mixed>  $changes
     * @return int the new version
     *
     * @throws VersionConflict when the row no longer has the expected version
     * @throws ResourceNotFound when no such row is visible
     */
    public static function apply(Builder $query, int $expectedVersion, array $changes): int
    {
        $updated = (clone $query)
            ->where('version', $expectedVersion)
            ->update([...$changes, 'version' => DB::raw('version + 1')]);

        if ($updated === 1) {
            return $expectedVersion + 1;
        }

        // Read inside the same transaction, so the reported current version is the one that blocked the update.
        $current = (clone $query)->value('version');

        if (! is_int($current)) {
            throw new ResourceNotFound;
        }

        throw new VersionConflict($expectedVersion, $current);
    }
}
