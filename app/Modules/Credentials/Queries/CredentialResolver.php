<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Queries;

use App\Modules\Credentials\Data\ResolvedCredential;
use App\Modules\Credentials\Domain\Exceptions\InvalidCredential;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialCache;
use App\Modules\Credentials\Support\CredentialSummaries;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\Tenancy;
use Psr\Clock\ClockInterface;

/**
 * Finds the credential a presented secret belongs to. The format and checksum are checked first, then the secret is hashed
 * under the current pepper (and the previous one, if configured) and looked up by hash, from the cache when it holds the
 * summary and from MySQL otherwise. Status, expiry and the end of a rotation overlap are read again on every call, so a
 * cached summary never extends a credential's life.
 *
 * An unknown secret and a malformed one answer the same way (InvalidCredential). A secret that matches a revoked, expired or
 * rotated-out credential answers RevokedCredential; the caller already holds that secret, so it discloses nothing.
 */
final class CredentialResolver
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly CredentialCache $cache,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidCredential
     * @throws RevokedCredential
     */
    public function resolve(string $presented): ResolvedCredential
    {
        if (TokenGenerator::parse($presented) === null) {
            throw new InvalidCredential;
        }

        $candidates = $this->hasher->candidates($presented);
        $summary = $this->fromCache($candidates) ?? $this->fromDatabase($candidates);

        if (! $summary->isUsableAt($this->clock->now())) {
            throw new RevokedCredential;
        }

        return $summary;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function fromCache(array $candidates): ?ResolvedCredential
    {
        foreach ($candidates as $hash) {
            $summary = $this->cache->get($hash);

            if ($summary !== null) {
                return $summary;
            }
        }

        foreach ($candidates as $hash) {
            if (! $this->cache->isKnownMissing($hash)) {
                return null;
            }
        }

        throw new InvalidCredential;
    }

    /**
     * @param  list<string>  $candidates
     *
     * @throws InvalidCredential
     */
    private function fromDatabase(array $candidates): ResolvedCredential
    {
        $credential = Tenancy::crossTenant('credential lookup finds the owner of a presented secret', fn (): ?Credential => Credential::query()
            ->whereIn('secret_hash', $candidates)
            ->first());

        if ($credential === null) {
            foreach ($candidates as $hash) {
                $this->cache->rememberMissing($hash);
            }

            throw new InvalidCredential;
        }

        $summary = CredentialSummaries::of($credential);
        $this->cache->fill($credential->secret_hash, $summary);

        return $summary;
    }
}
