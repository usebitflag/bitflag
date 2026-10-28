<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Data\TrackKnownDeviceData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Domain\DeviceLabel;
use App\Modules\Identity\Domain\GeoLocator;
use App\Modules\Identity\Models\KnownDevice;
use App\Shared\Security\SecretHasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * Remembers the devices a person signs in from, by a random value in a long-lived cookie. The first sign-in from a device
 * not seen before is announced by email, except a person's very first device, which has nothing to compare with.
 * Returns the cookie value to keep: the one it was given, or a new one.
 */
final class TrackKnownDevice
{
    public function __construct(
        private readonly SecretHasher $hasher,
        private readonly RequestAccountEmail $accountEmail,
        private readonly GeoLocator $geo,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(TrackKnownDeviceData $data): string
    {
        $token = $data->deviceToken !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $data->deviceToken) === 1
            ? $data->deviceToken
            : rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = $this->hasher->hash($token);
        $now = $this->clock->now();

        $device = KnownDevice::query()->where('user_id', $data->userId)->where('device_hash', $hash)->first();

        if ($device !== null) {
            $device->forceFill(['last_seen_at' => $now])->save();

            return $token;
        }

        $label = DeviceLabel::fromUserAgent($data->userAgent);
        $hadOthers = KnownDevice::query()->where('user_id', $data->userId)->exists();

        try {
            DB::transaction(function () use ($data, $hash, $label, $now, $hadOthers): void {
                KnownDevice::query()->create([
                    'user_id' => $data->userId,
                    'device_hash' => $hash,
                    'device_label' => $label,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                if ($hadOthers) {
                    $place = $data->ipAddress !== null ? $this->geo->locate($data->ipAddress) : null;
                    $details = ['device_label' => $label] + ($place !== null ? ['location' => $place] : []);

                    $this->accountEmail->handle(new RequestAccountEmailData(AccountEmailTemplate::NewSignIn, $data->userId, $details));
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ins from the same new device raced; the other one recorded it.
        }

        return $token;
    }
}
