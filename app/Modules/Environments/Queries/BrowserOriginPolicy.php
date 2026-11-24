<?php

declare(strict_types=1);

namespace App\Modules\Environments\Queries;

use App\Modules\Environments\Data\EnvironmentDeliverySettingsData;
use App\Modules\Environments\Domain\Exceptions\OriginNotAllowed;
use App\Modules\Environments\Domain\OriginRules;

final class BrowserOriginPolicy
{
    /** @throws OriginNotAllowed */
    public function check(EnvironmentDeliverySettingsData $environment, ?string $origin): void
    {
        $normalized = $origin === null ? null : OriginRules::normalize($origin);

        if ($normalized === null || ! in_array($normalized, $environment->allowedOrigins, true)) {
            throw new OriginNotAllowed;
        }
    }
}
