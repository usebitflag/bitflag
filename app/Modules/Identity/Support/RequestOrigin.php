<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\DeviceLabel;
use App\Modules\Identity\Domain\GeoLocator;

/**
 * Describes where a request came from for an email ("Chrome on macOS", "Manila, Philippines"), as the short details an
 * email request may carry. It never keeps the address or the User-Agent text.
 */
final class RequestOrigin
{
    public function __construct(private readonly GeoLocator $geo) {}

    /**
     * @return array{device_label: string, location?: string}
     */
    public function describe(?string $ipAddress, ?string $userAgent): array
    {
        $place = $ipAddress !== null ? $this->geo->locate($ipAddress) : null;

        return ['device_label' => DeviceLabel::fromUserAgent($userAgent)] + ($place !== null ? ['location' => $place] : []);
    }
}
