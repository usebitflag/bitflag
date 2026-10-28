<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use DateTimeImmutable;

/**
 * What the platform knows about a presented credential, without its secret. This is also what the credential cache holds:
 * the status and the two expiry times are read again on every request, so a cached summary never extends a credential's life.
 */
final readonly class ResolvedCredential
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $id,
        public CredentialType $type,
        public string $organizationId,
        public string $projectId,
        public ?string $environmentId,
        public ?string $userId,
        public array $scopes,
        public CredentialStatus $status,
        public ?int $expiresAtMs,
        public ?int $overlapEndsAtMs,
        public CredentialAudience $audience,
        public string $label,
        public string $displayPrefix,
        public string $last4,
        public ?string $createdBy,
    ) {}

    /**
     * Whether the credential authenticates at the moment.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        $ms = (int) $now->format('Uv');

        if ($this->expiresAtMs !== null && $ms >= $this->expiresAtMs) {
            return false;
        }

        return match ($this->status) {
            CredentialStatus::Active => true,
            CredentialStatus::Rotating => $this->overlapEndsAtMs === null || $ms < $this->overlapEndsAtMs,
            CredentialStatus::Expired, CredentialStatus::Revoked => false,
        };
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'environment_id' => $this->environmentId,
            'user_id' => $this->userId,
            'scopes' => $this->scopes,
            'status' => $this->status->value,
            'expires_at_ms' => $this->expiresAtMs,
            'overlap_ends_at_ms' => $this->overlapEndsAtMs,
            'audience' => $this->audience->value,
            'label' => $this->label,
            'display_prefix' => $this->displayPrefix,
            'last4' => $this->last4,
            'created_by' => $this->createdBy,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = CredentialType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        $status = CredentialStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '');
        $audience = CredentialAudience::tryFrom(is_string($data['audience'] ?? null) ? $data['audience'] : '');
        $scopes = $data['scopes'] ?? null;

        if ($type === null || $status === null || $audience === null || ! is_array($scopes)
            || ! is_string($data['id'] ?? null) || ! is_string($data['organization_id'] ?? null) || ! is_string($data['project_id'] ?? null)) {
            return null;
        }

        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            $data['id'],
            $type,
            $data['organization_id'],
            $data['project_id'],
            $string('environment_id'),
            $string('user_id'),
            array_values(array_filter($scopes, 'is_string')),
            $status,
            $int('expires_at_ms'),
            $int('overlap_ends_at_ms'),
            $audience,
            $string('label') ?? '',
            $string('display_prefix') ?? '',
            $string('last4') ?? '',
            $string('created_by'),
        );
    }
}
