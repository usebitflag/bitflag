<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\RedisClient;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Copies the last-use times recorded in Redis into `credentials.last_used_at`, in chunks of 500. The set and the client hash
 * are renamed first, so uses recorded while it runs go to the next flush. A time never moves backwards.
 */
final class FlushCredentialLastUsed
{
    public const CHUNK = 500;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return int how many credentials were updated */
    public function handle(): int
    {
        $redis = RedisClient::get();
        $suffix = bin2hex(random_bytes(4));
        $times = CredentialUsageRecorder::LAST_USED_KEY.":flushing:{$suffix}";
        $clients = CredentialUsageRecorder::CLIENT_KEY.":flushing:{$suffix}";

        // Both renames in one script, so a use is never split between a set that was taken and a hash that was not.
        $taken = $redis->eval(<<<'LUA'
            if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
            redis.call('RENAME', KEYS[1], KEYS[3])
            if redis.call('EXISTS', KEYS[2]) == 1 then redis.call('RENAME', KEYS[2], KEYS[4]) end
            return 1
            LUA, [CredentialUsageRecorder::LAST_USED_KEY, CredentialUsageRecorder::CLIENT_KEY, $times, $clients], 4);

        if ($taken === 0 || $taken === false) {
            return 0;
        }

        $updated = 0;
        $entries = $redis->zRange($times, 0, -1, ['WITHSCORES' => true]);

        foreach (array_chunk(is_array($entries) ? $entries : [], self::CHUNK, true) as $chunk) {
            foreach ($chunk as $member => $score) {
                [$organizationId, $credentialId] = array_pad(explode(':', (string) $member, 2), 2, '');

                if ($organizationId === '' || $credentialId === '') {
                    continue;
                }

                $client = $redis->hGet($clients, (string) $member);
                $at = CarbonImmutable::createFromTimestampMsUTC(is_numeric($score) ? (int) $score : 0);

                $updated += $this->tenant->run($organizationId, fn (): int => Credential::query()->whereKey($credentialId)
                    ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $at))
                    ->update(['last_used_at' => $at, 'last_used_client' => is_string($client) && $client !== '' ? $client : null]));
            }
        }

        $redis->del($times, $clients);

        return $updated;
    }
}
