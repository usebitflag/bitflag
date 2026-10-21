<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Illuminate\Database\Eloquent\Builder;

/**
 * A query builder that refuses every write except insert, so audit entries cannot be changed through a query either.
 *
 * @extends Builder<AuditEvent>
 */
final class AuditEventBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): never
    {
        throw AuditEventImmutable::cannot('update');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>|string  $uniqueBy
     * @param  array<array-key, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw AuditEventImmutable::cannot('upsert');
    }

    public function delete(): never
    {
        throw AuditEventImmutable::cannot('delete');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw AuditEventImmutable::cannot('increment');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw AuditEventImmutable::cannot('decrement');
    }

    public function truncate(): never
    {
        throw AuditEventImmutable::cannot('truncate');
    }
}
