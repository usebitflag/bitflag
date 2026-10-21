<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * What an idempotency key is unique within: one organization, one actor, one operation. The same key sent for a
 * different operation or by a different person is a different request.
 */
final readonly class IdempotencyScope
{
    /**
     * @param  string  $actorKey  Public ID of the user or token, for example usr_01j9x3….
     * @param  string  $operation  The action name, for example publish_environment.
     */
    public function __construct(
        public string $organizationId,
        public string $actorKey,
        public string $operation,
    ) {}
}
