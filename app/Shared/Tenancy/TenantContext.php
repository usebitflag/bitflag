<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;

/**
 * The organization the current request or job acts for. Bound as a scoped singleton, so a long-running worker starts
 * every job with a clean context.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    private int $bypassDepth = 0;

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function set(string $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    /**
     * Runs the callback for another organization and restores the previous context afterwards, even on failure.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $organizationId, Closure $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * Runs the callback with tenant scoping switched off. Use Tenancy::crossTenant(), which also records why.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutScoping(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
