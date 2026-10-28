<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\TrustedBrowser;
use App\Shared\Errors\ResourceNotFound;
use Psr\Clock\ClockInterface;

/**
 * Stops one browser from skipping the second-factor prompt. A person can only revoke their own.
 */
final class RevokeTrustedBrowser
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @throws ResourceNotFound
     */
    public function handle(string $userId, string $browserId): void
    {
        $revoked = TrustedBrowser::query()
            ->where('id', $browserId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $this->clock->now()]);

        if ($revoked !== 1) {
            throw new ResourceNotFound;
        }
    }
}
