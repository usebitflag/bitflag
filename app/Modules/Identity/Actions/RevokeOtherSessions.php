<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecordSecurityEventData;
use App\Modules\Identity\Data\RevokeOtherSessionsData;
use App\Modules\Identity\Domain\SecurityEventType;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * "Sign out everywhere else": deletes every other session of the person and stops every trusted browser from skipping
 * their second factor. Returns how many sessions ended.
 */
final class RevokeOtherSessions
{
    public function __construct(
        private readonly RecordSecurityEvent $securityLog,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(RevokeOtherSessionsData $data): int
    {
        return DB::transaction(function () use ($data): int {
            $ended = DB::table('sessions')->where('user_id', $data->userId)->where('id', '!=', $data->currentSessionId)->delete();

            DB::table('trusted_browsers')->where('user_id', $data->userId)->whereNull('revoked_at')->update(['revoked_at' => $this->clock->now()]);

            if ($ended > 0) {
                $this->securityLog->handle(new RecordSecurityEventData(SecurityEventType::SessionRevoked, $data->userId, null, $data->ipAddress, $data->userAgent));
            }

            return $ended;
        });
    }
}
