<?php

declare(strict_types=1);

namespace App\Shared\Ids;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a ULID-keyed model its public identifier. The model declares its type by implementing publicIdPrefix().
 *
 * Route binding by public ID resolves through the model's normal query, so global scopes (such as the tenant scope)
 * apply: a valid ID that belongs to another organization is a 404.
 *
 * @mixin Model
 */
trait HasPublicId
{
    abstract public static function publicIdPrefix(): IdPrefix;

    /**
     * @return Attribute<string, never>
     */
    protected function publicId(): Attribute
    {
        return Attribute::get(function (): string {
            $key = $this->getKey();

            if (! is_string($key)) {
                throw new \LogicException('A public ID needs a saved model with a ULID key.');
            }

            return PublicId::encode(static::publicIdPrefix(), $key);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->whereKey(PublicId::decode(static::publicIdPrefix(), $publicId));
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field  A slug or key column when the route names one; the public ID otherwise.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        if (! is_string($value)) {
            return null;
        }

        return $this->newQuery()->wherePublicId($value)->first();
    }
}
