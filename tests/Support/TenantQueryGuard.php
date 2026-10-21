<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Fails a test when a query touches a tenant table without an organization_id predicate. Queries run inside
 * Tenancy::crossTenant() are exempt, because that is the one deliberate way to read across organizations.
 *
 * The check is textual: a statement on a tenant table must mention organization_id, in its WHERE clause or, for inserts,
 * in its column list. Enabled for every feature test in tests/Pest.php.
 */
final class TenantQueryGuard
{
    /** @var list<string> */
    private static array $violations = [];

    private static int $paused = 0;

    public static function start(): void
    {
        self::$violations = [];
        self::$paused = 0;

        DB::listen(static function (QueryExecuted $query): void {
            if (self::$paused > 0 || app(TenantContext::class)->isBypassed()) {
                return;
            }

            if (self::violates($query->sql)) {
                self::$violations[] = $query->sql;
            }
        });
    }

    public static function assertClean(): void
    {
        $violations = self::$violations;
        self::$violations = [];

        Assert::assertSame([], $violations, "Queries on tenant tables without an organization_id predicate:\n".implode("\n", $violations));
    }

    /**
     * Runs the callback without checking its queries, for tests that exercise the guard or set up raw data.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function paused(Closure $callback): mixed
    {
        self::$paused++;

        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    /**
     * Violations recorded so far; clears them.
     *
     * @return list<string>
     */
    public static function takeViolations(): array
    {
        $violations = self::$violations;
        self::$violations = [];

        return $violations;
    }

    public static function violates(string $sql): bool
    {
        $normalized = strtolower(str_replace('`', '', $sql));

        if (preg_match('/^\s*(create|alter|drop|truncate|rename|set|start|commit|rollback|savepoint|release)\b/', $normalized) === 1) {
            return false;
        }

        $tenantTables = array_keys(self::tenantTables());
        preg_match_all('/\b(?:from|join|update|into)\s+([a-z_][a-z0-9_]*)/', $normalized, $matches);

        $touched = array_intersect($matches[1], $tenantTables);

        return $touched !== [] && ! str_contains($normalized, 'organization_id');
    }

    /**
     * @return array<string, string>
     */
    private static function tenantTables(): array
    {
        /** @var array<string, string> $tables */
        $tables = config('tenancy.tenant', []);

        return $tables;
    }
}
