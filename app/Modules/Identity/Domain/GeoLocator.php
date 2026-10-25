<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Turns an IP address into a coarse place name for the session list and sign-in emails ("Berlin, Germany").
 */
interface GeoLocator
{
    /**
     * Null when the place is unknown; the interface then shows "Unknown location".
     */
    public function locate(string $ipAddress): ?string;
}
