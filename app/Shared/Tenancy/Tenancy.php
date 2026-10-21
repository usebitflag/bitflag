<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * The single, deliberate way to read or write across organizations. It is allowed for the outbox dispatcher, scheduled
 * maintenance, the admin console and the lookup that resolves a request's scope. Every use states its reason.
 */
final class Tenancy
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function crossTenant(string $reason, Closure $callback): mixed
    {
        Log::debug('Cross-tenant access', ['reason' => $reason]);

        return app(TenantContext::class)->withoutScoping($callback);
    }
}
