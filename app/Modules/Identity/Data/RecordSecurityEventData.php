<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Domain\SecurityEventType;

/**
 * An entry for the security log. Give the person's id when there is one; otherwise give the address that was tried, and
 * only a hash of it is kept.
 */
final readonly class RecordSecurityEventData
{
    public function __construct(
        public SecurityEventType $type,
        public ?string $userId,
        public ?string $email,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
