<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Modules\Identity\Models\TrustedBrowser;
use Psr\Clock\ClockInterface;

/**
 * The browsers that currently skip a person's second-factor prompt.
 */
final class TrustedBrowsers
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return list<array{id: string, device: string, trustedAt: int|null, lastUsedAt: int|null, expiresAt: int}>
     */
    public function for(string $userId): array
    {
        $browsers = TrustedBrowser::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->now())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TrustedBrowser $browser): array => [
                'id' => $browser->id,
                'device' => $browser->device_label,
                'trustedAt' => $browser->created_at?->getTimestamp(),
                'lastUsedAt' => $browser->last_used_at?->getTimestamp(),
                'expiresAt' => $browser->expires_at->getTimestamp(),
            ])
            ->all();

        return array_values($browsers);
    }
}
