<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\DeviceLabel;
use App\Modules\Identity\Domain\GeoLocator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session handler, plus what the "Your sessions" list needs: when the session began, a readable device
 * name and an estimated place. Those are worked out once, when the session row is first written; later writes only
 * move the activity time. Revoking a session deletes its row.
 */
final class TrackedDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function __construct(
        ConnectionInterface $connection,
        string $table,
        int $minutes,
        Container $container,
        private readonly GeoLocator $geoLocator,
    ) {
        parent::__construct($connection, $table, $minutes, $container);
    }

    /**
     * @param  string  $sessionId
     * @param  array<string, mixed>  $payload
     */
    protected function performInsert($sessionId, $payload)
    {
        $ipAddress = $payload['ip_address'] ?? null;
        $userAgent = $payload['user_agent'] ?? null;

        $payload['created_at'] = $this->currentTime();
        $payload['device_label'] = DeviceLabel::fromUserAgent(is_string($userAgent) ? $userAgent : null);
        $payload['location_estimate'] = is_string($ipAddress) && $ipAddress !== ''
            ? $this->geoLocator->locate($ipAddress)
            : null;

        return parent::performInsert($sessionId, $payload);
    }
}
