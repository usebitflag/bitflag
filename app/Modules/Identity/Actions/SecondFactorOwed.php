<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\TrustedBrowser;
use App\Modules\Identity\Models\User;
use App\Shared\Security\SecretHasher;
use Psr\Clock\ClockInterface;

/**
 * Whether a person who has just proved who they are, by password or by a provider, still owes a second factor. They do when
 * they have a confirmed one and this browser is not trusted.
 */
final class SecondFactorOwed
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(User $user, ?string $trustedBrowserCookie): bool
    {
        return $this->hasConfirmedSecondFactor($user) && ! $this->browserIsTrusted($user, $trustedBrowserCookie);
    }

    private function hasConfirmedSecondFactor(User $user): bool
    {
        return MfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists();
    }

    /**
     * The cookie is `{id}.{secret}`. It counts only for this person, unrevoked and unexpired, and only if the secret matches
     * the stored hash.
     */
    private function browserIsTrusted(User $user, ?string $cookie): bool
    {
        if ($cookie === null || substr_count($cookie, '.') !== 1) {
            return false;
        }

        [$id, $secret] = explode('.', $cookie);

        $browser = TrustedBrowser::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->now())
            ->first();

        if ($browser === null || ! $this->hasher->matches($secret, $browser->token_hash)) {
            return false;
        }

        $browser->forceFill(['last_used_at' => $this->clock->now()])->save();

        return true;
    }
}
