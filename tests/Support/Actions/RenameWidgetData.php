<?php

declare(strict_types=1);

namespace Tests\Support\Actions;

final readonly class RenameWidgetData
{
    public function __construct(
        public string $organizationId,
        public string $projectId,
        public string $widgetId,
        public int $expectedVersion,
        public string $name,
        public string $idempotencyKey,
        /** Makes the outbox push fail, to prove the whole change rolls back. */
        public bool $failOutbox = false,
    ) {}
}
