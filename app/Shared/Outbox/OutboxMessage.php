<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Ids\IdPrefix;
use Illuminate\Support\Str;

/**
 * A durable notice that something happened, pushed in the same transaction as the change. Payloads carry identifiers
 * and versions only: never secrets, rule contents or subject data.
 *
 * Types are webhook event names (revision.published, flag.changed, …) or start with `internal.`. The message ID becomes
 * the webhook event ID.
 */
final readonly class OutboxMessage
{
    private const TYPE = '/^(internal\.[a-z0-9_.]+|revision\.published|review\.requested|review\.decided|flag\.changed|key\.revoked|experiment\.started|experiment\.stopped)$/';

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function __construct(
        public string $id,
        public OutboxScope $scope,
        public ?string $organizationId,
        public ?string $userId,
        public ?string $projectId,
        public ?string $environmentId,
        public string $type,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public ?string $requestId = null,
    ) {
        if (preg_match(self::TYPE, $type) !== 1) {
            throw new \InvalidArgumentException("Outbox message type \"{$type}\" must be a webhook event name or start with internal.");
        }
    }

    /**
     * An event about an organization, optionally narrowed to a project and environment.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function forOrganization(
        string $organizationId,
        string $type,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $projectId = null,
        ?string $environmentId = null,
    ): self {
        return new self(self::newId(), OutboxScope::Organization, $organizationId, null, $projectId, $environmentId, $type, $aggregateType, $aggregateId, $payload);
    }

    /**
     * An event about a person before or outside any organization, such as sign-up or password reset.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function forAccount(string $userId, string $type, string $aggregateType, string $aggregateId, array $payload = []): self
    {
        return new self(self::newId(), OutboxScope::Account, null, $userId, null, null, $type, $aggregateType, $aggregateId, $payload);
    }

    /**
     * Platform maintenance that belongs to no organization or person.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function forSystem(string $type, string $aggregateType, string $aggregateId, array $payload = []): self
    {
        return new self(self::newId(), OutboxScope::System, null, null, null, null, $type, $aggregateType, $aggregateId, $payload);
    }

    /**
     * The same message as read back from storage, with the request that caused it.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function restore(
        string $id,
        OutboxScope $scope,
        ?string $organizationId,
        ?string $userId,
        ?string $projectId,
        ?string $environmentId,
        string $type,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $requestId,
    ): self {
        return new self($id, $scope, $organizationId, $userId, $projectId, $environmentId, $type, $aggregateType, $aggregateId, $payload, $requestId);
    }

    public function withRequestId(?string $requestId): self
    {
        return new self($this->id, $this->scope, $this->organizationId, $this->userId, $this->projectId, $this->environmentId, $this->type, $this->aggregateType, $this->aggregateId, $this->payload, $requestId);
    }

    /**
     * The identifier shown to webhook receivers.
     */
    public function eventId(): string
    {
        return IdPrefix::Event->value.$this->id;
    }

    private static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
