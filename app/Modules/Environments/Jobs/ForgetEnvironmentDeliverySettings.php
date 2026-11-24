<?php

declare(strict_types=1);

namespace App\Modules\Environments\Jobs;

use App\Modules\Environments\Queries\EnvironmentDeliverySettings;
use App\Shared\Outbox\OutboxConsumer;
use App\Shared\Outbox\OutboxMessage;
use Illuminate\Contracts\Cache\Repository;

final class ForgetEnvironmentDeliverySettings implements OutboxConsumer
{
    public function __construct(private readonly Repository $cache) {}

    public function name(): string
    {
        return 'environments.forget_delivery_settings';
    }

    public function handles(): array
    {
        return ['internal.environment.delivery_settings_changed', 'internal.environment.archived'];
    }

    public function queue(): string
    {
        return 'publication';
    }

    public function timeoutSeconds(): int
    {
        return 30;
    }

    public function consume(OutboxMessage $message): void
    {
        $environmentId = $message->payload['environment_id'] ?? $message->environmentId;

        if (is_string($environmentId)) {
            $this->cache->forget(EnvironmentDeliverySettings::cacheKey($environmentId));
        }
    }
}
