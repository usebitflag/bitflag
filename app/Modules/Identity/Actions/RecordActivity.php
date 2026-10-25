<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Psr\Clock\ClockInterface;

/**
 * Sets when a person was last active. It does nothing if that was less than five minutes ago, so a busy page does not
 * write on every request.
 */
final class RecordActivity
{
    private const MINUTES = 5;

    public function __construct(private readonly ClockInterface $clock) {}

    public function handle(string $userId): void
    {
        $now = $this->clock->now();

        User::query()
            ->whereKey($userId)
            ->where(fn ($query) => $query->whereNull('last_active_at')->orWhere('last_active_at', '<=', $now->modify('-'.self::MINUTES.' minutes')))
            ->update(['last_active_at' => $now]);
    }
}
