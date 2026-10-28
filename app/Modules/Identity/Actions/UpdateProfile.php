<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\UpdateProfileData;
use App\Modules\Identity\Models\User;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Changes the name and time zone on a person's own account. The time zone is an IANA name such as `Europe/Berlin`. A
 * preference, so it is not audited.
 */
final class UpdateProfile
{
    /**
     * @throws ValidationException
     */
    public function handle(UpdateProfileData $data): void
    {
        $name = trim($data->name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter your name, up to 120 characters.']);
        }

        if (! in_array($data->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a time zone from the list.']);
        }

        User::query()->findOrFail($data->userId)->forceFill(['name' => $name, 'timezone' => $data->timezone])->save();
    }
}
