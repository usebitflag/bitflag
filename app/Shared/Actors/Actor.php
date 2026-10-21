<?php

declare(strict_types=1);

namespace App\Shared\Actors;

use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\CapabilityResolver;
use App\Shared\Capabilities\Scope;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;

/**
 * The identity behind an action: a person, a project management token, the system, or support staff. Actions receive
 * an Actor as an argument and never read the session or request themselves.
 */
final readonly class Actor
{
    private function __construct(
        private ActorType $type,
        private ?string $id,
        private string $displayName,
        private Channel $channel,
        private ?string $viaTokenId = null,
        private ?string $tokenCreatorId = null,
        private ?string $projectId = null,
        private ?string $ticket = null,
    ) {}

    public static function user(string $userId, string $displayName, Channel $channel): self
    {
        return new self(ActorType::User, $userId, $displayName, $channel);
    }

    /**
     * A person acting through a personal access token. The token can narrow what the person may do.
     */
    public static function personalToken(string $userId, string $displayName, string $tokenId, Channel $channel): self
    {
        return new self(ActorType::User, $userId, $displayName, $channel, viaTokenId: $tokenId);
    }

    /**
     * A project management token. It is owned by the project and keeps working when its creator leaves.
     */
    public static function managementToken(string $tokenId, string $creatorUserId, string $projectId, Channel $channel): self
    {
        return new self(ActorType::ManagementToken, $tokenId, 'Management key', $channel, viaTokenId: $tokenId, tokenCreatorId: $creatorUserId, projectId: $projectId);
    }

    public static function system(string $process): self
    {
        return new self(ActorType::System, null, $process, Channel::System);
    }

    public static function support(string $staffUserId, string $displayName, ?string $ticket): self
    {
        return new self(ActorType::Support, $staffUserId, $displayName, Channel::Support, ticket: $ticket);
    }

    public function can(Capability $capability, Scope $scope): bool
    {
        return app(CapabilityResolver::class)->can($this, $capability, $scope);
    }

    public function type(): ActorType
    {
        return $this->type;
    }

    /**
     * The raw identifier: a user ULID, a token ID, a staff ID, or null for the system.
     */
    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * The identifier as shown to clients, for example usr_01j9x3…. Null for the system and for staff.
     */
    public function publicId(): ?string
    {
        if ($this->id === null || $this->type !== ActorType::User) {
            return null;
        }

        return PublicId::encode(IdPrefix::User, $this->id);
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function viaTokenId(): ?string
    {
        return $this->viaTokenId;
    }

    public function tokenCreatorId(): ?string
    {
        return $this->tokenCreatorId;
    }

    /**
     * The project a management token is bound to; null for every other actor.
     */
    public function boundProjectId(): ?string
    {
        return $this->projectId;
    }

    public function ticket(): ?string
    {
        return $this->ticket;
    }

    /**
     * The actor columns of an audit entry.
     *
     * @return array{actor_type: string, actor_id: string|null, actor_display: string, via_token_id: string|null, token_creator_id: string|null, channel: string}
     */
    public function auditAttributes(): array
    {
        return [
            'actor_type' => $this->type->value,
            'actor_id' => $this->id,
            'actor_display' => $this->displayName,
            'via_token_id' => $this->viaTokenId,
            'token_creator_id' => $this->tokenCreatorId,
            'channel' => $this->channel->value,
        ];
    }
}
