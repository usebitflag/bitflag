<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Psr\Clock\ClockInterface;

/**
 * The credentials the scheduler has work for: rotations whose overlap has ended and credentials past their expiry.
 */
final class DueCredentials
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return list<array{organizationId: string, credentialId: string}>
     */
    public function rotationsToComplete(int $limit = 500): array
    {
        return $this->rows(fn ($query) => $query->where('status', CredentialStatus::Rotating->value)->where('overlap_ends_at', '<=', $this->clock->now()), $limit);
    }

    /**
     * @return list<array{organizationId: string, credentialId: string}>
     */
    public function toExpire(int $limit = 500): array
    {
        return $this->rows(fn ($query) => $query->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])->where('expires_at', '<=', $this->clock->now()), $limit);
    }

    /**
     * Active credentials that expire within the given number of days.
     *
     * @return list<array{organizationId: string, credentialId: string}>
     */
    public function expiringWithin(int $days, int $limit = 1000): array
    {
        return $this->rows(fn ($query) => $query->where('status', CredentialStatus::Active->value)->where('expires_at', '>', $this->clock->now())->where('expires_at', '<=', $this->clock->now()->modify("+{$days} days")), $limit);
    }

    /**
     * @param  callable(Builder<Credential>): Builder<Credential>  $narrow
     * @return list<array{organizationId: string, credentialId: string}>
     */
    private function rows(callable $narrow, int $limit): array
    {
        return Tenancy::crossTenant('the scheduler finds due credentials in every organization', fn (): array => array_values($narrow(Credential::query())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (Credential $credential): array => ['organizationId' => $credential->organization_id, 'credentialId' => $credential->id])
            ->all()));
    }
}
