<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use LogicException;

/**
 * Audit entries are append-only. Nothing in the application updates or deletes one.
 */
final class AuditEventImmutable extends LogicException
{
    public static function cannot(string $operation): self
    {
        return new self("Audit events are append-only; {$operation} is not allowed.");
    }
}
