<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\GeoLocator;

/**
 * Used until a GeoIP source is chosen. Nothing leaves the application and no IP address is looked up.
 */
final class NullGeoLocator implements GeoLocator
{
    public function locate(string $ipAddress): ?string
    {
        return null;
    }
}
