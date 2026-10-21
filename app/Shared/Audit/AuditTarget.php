<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * The object an audit entry is about. The display text is kept on the entry so history stays readable after the object
 * is renamed or deleted.
 */
final readonly class AuditTarget
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $display = null,
    ) {}
}
