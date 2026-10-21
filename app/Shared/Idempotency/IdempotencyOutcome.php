<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * The result of Idempotency::run: either the operation just ran, or an earlier result is being replayed.
 */
final readonly class IdempotencyOutcome
{
    public function __construct(public OperationResult $result, public bool $replayed, private bool $secretNotReplayable = false) {}

    /**
     * The body to send. A replay of an operation that issued a secret says so instead of repeating it.
     *
     * @return array<array-key, mixed>
     */
    public function body(): array
    {
        if ($this->replayed && $this->secretNotReplayable) {
            $details = is_array($this->result->body['details'] ?? null) ? $this->result->body['details'] : [];

            return [...$this->result->body, 'details' => [...$details, 'secret_not_replayable' => true]];
        }

        return $this->result->body;
    }

    public function status(): int
    {
        return $this->result->status;
    }
}
