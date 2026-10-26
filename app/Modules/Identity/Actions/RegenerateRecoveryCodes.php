<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RegenerateRecoveryCodesData;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Makes a new set of recovery codes for someone who has two-factor on. Needs the password, and the old codes stop working.
 */
final class RegenerateRecoveryCodes
{
    public function __construct(private readonly GenerateRecoveryCodes $codes) {}

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function handle(RegenerateRecoveryCodesData $data): array
    {
        $user = User::query()->findOrFail($data->userId);

        if (! $user->passwordMatches($data->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        if (! MfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists()) {
            throw ValidationException::withMessages(['two_factor' => 'Turn on two-factor sign-in first.']);
        }

        return $this->codes->handle($user->id);
    }
}
