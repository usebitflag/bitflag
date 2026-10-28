<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\SessionPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Daily housekeeping for security data: security events older than 90 days, sessions past their absolute lifetime or
 * idle past the platform limit, trusted browsers that expired or were revoked, and second-factor enrollments left
 * unconfirmed for a day. Returns how many rows went, by kind.
 */
final class PruneSecurityState
{
    public const SECURITY_EVENT_DAYS = 90;

    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @return array{security_events: int, sessions: int, trusted_browsers: int, enrollments: int}
     */
    public function handle(): array
    {
        $now = $this->clock->now();
        $timestamp = $now->getTimestamp();

        return [
            'security_events' => DB::table('security_events')->where('created_at', '<', $now->modify('-'.self::SECURITY_EVENT_DAYS.' days'))->delete(),
            'sessions' => DB::table('sessions')
                ->where(fn (Builder $query) => $query
                    ->where('last_activity', '<', $timestamp - SessionPolicy::PLATFORM_IDLE_MINUTES * 60)
                    ->orWhere(fn (Builder $old) => $old->where('created_at', '>', 0)->where('created_at', '<', $timestamp - SessionPolicy::ABSOLUTE_MINUTES * 60)))
                ->delete(),
            'trusted_browsers' => DB::table('trusted_browsers')
                ->where(fn (Builder $query) => $query->where('expires_at', '<', $now)->orWhere('revoked_at', '<', $now->modify('-1 day')))
                ->delete(),
            'enrollments' => DB::table('mfa_factors')->whereNull('confirmed_at')->where('created_at', '<', $now->modify('-1 day'))->delete(),
        ];
    }
}
