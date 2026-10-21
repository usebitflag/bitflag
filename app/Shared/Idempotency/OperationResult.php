<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * What an idempotent operation produced. The body is public metadata that is stored and replayed. One-time secrets, such
 * as a newly issued key, are returned to the first caller only and are never stored.
 */
final readonly class OperationResult
{
    /**
     * @param  array<array-key, mixed>  $body  Stored and replayed; must not contain secrets.
     * @param  array<array-key, mixed>  $oneTimeSecrets  Returned once, never stored.
     */
    public function __construct(
        public int $status,
        public array $body,
        public ?string $resultType = null,
        public ?string $resultId = null,
        public array $oneTimeSecrets = [],
    ) {}

    public function hasOneTimeSecrets(): bool
    {
        return $this->oneTimeSecrets !== [];
    }
}
