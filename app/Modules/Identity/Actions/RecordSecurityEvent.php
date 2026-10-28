<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Models\SecurityEvent;
use App\Shared\Security\SecretHasher;
use Psr\Clock\ClockInterface;

/**
 * Writes one entry to the security log (kept 90 days). It is separate from the customer audit and not authorized by a
 * capability: it records what happened to an account, including before anyone is signed in.
 */
final class RecordSecurityEvent
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(RecordSecurityEventData $data): void
    {
        // The address of an attempt against an unknown account is never stored, only a peppered hash of it.
        $emailHash = $data->userId === null && $data->email !== null
            ? $this->hasher->hash(mb_strtolower(trim($data->email)))
            : null;

        SecurityEvent::query()->create([
            'user_id' => $data->userId,
            'email_hash' => $emailHash,
            'type' => $data->type,
            'ip_address' => $data->ipAddress,
            'user_agent' => $data->userAgent,
            'created_at' => $this->clock->now(),
        ]);
    }
}
