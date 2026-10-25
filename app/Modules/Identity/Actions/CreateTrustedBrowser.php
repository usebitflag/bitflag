<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\DeviceLabel;
use App\Modules\Identity\Models\TrustedBrowser;
use App\Shared\Security\SecretHasher;
use Psr\Clock\ClockInterface;

/**
 * Marks this browser as trusted for thirty days: it skips the second-factor prompt at sign-in. Returns the cookie value
 * (`{id}.{secret}`); only a peppered hash of the secret is stored.
 */
final class CreateTrustedBrowser
{
    public const DAYS = 30;

    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $userId, ?string $userAgent): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();

        $browser = TrustedBrowser::query()->create([
            'user_id' => $userId,
            'token_hash' => $this->hasher->hash($secret),
            'device_label' => DeviceLabel::fromUserAgent($userAgent),
            'created_at' => $now,
            'expires_at' => $now->modify('+'.self::DAYS.' days'),
            'last_used_at' => $now,
        ]);

        return $browser->id.'.'.$secret;
    }
}
