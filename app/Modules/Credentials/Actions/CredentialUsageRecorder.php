<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Support\RedisClient;
use Throwable;

/**
 * Notes that a credential was just used, without a database write per request. At most once a minute per credential it adds
 * the credential to a sorted set of last-use times; `FlushCredentialLastUsed` copies the set into `credentials.last_used_at`
 * every minute. The time shown is accurate to about a minute. When Redis is down nothing is recorded and authentication
 * carries on from MySQL.
 */
final class CredentialUsageRecorder
{
    public const SEEN_TTL_SECONDS = 60;

    public const LAST_USED_KEY = 'bf:cred:last_used';

    public const CLIENT_KEY = 'bf:cred:client';

    public function handle(string $organizationId, string $credentialId, ?string $client = null): void
    {
        try {
            $redis = RedisClient::get();

            if (! $redis->set("bf:cred:seen:{$credentialId}", '1', ['nx', 'ex' => self::SEEN_TTL_SECONDS])) {
                return;
            }

            // The member names the organization too, so the flush can update inside the right tenant.
            $member = "{$organizationId}:{$credentialId}";
            $redis->zAdd(self::LAST_USED_KEY, ['GT'], (int) floor(microtime(true) * 1000), $member);

            if ($client !== null && $client !== '') {
                $redis->hSet(self::CLIENT_KEY, $member, mb_substr($client, 0, 60));
            }
        } catch (Throwable) {
            // Usage is a convenience for the key list; never fail a request over it.
        }
    }
}
