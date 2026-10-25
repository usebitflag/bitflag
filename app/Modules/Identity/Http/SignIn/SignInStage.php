<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\SignIn;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Passes the person found by the password check from one stage of the sign-in pipeline to the next. The pipeline never
 * logs anyone in until the last stage, so the guard cannot see a half-finished sign-in.
 */
final class SignInStage
{
    private const ATTRIBUTE = 'bitflag.sign_in.user';

    public static function remember(Request $request, User $user): void
    {
        $request->attributes->set(self::ATTRIBUTE, $user);
    }

    public static function rememberDeviceToken(Request $request, string $token): void
    {
        $request->attributes->set('bitflag.sign_in.device_token', $token);
    }

    public static function deviceToken(Request $request): ?string
    {
        $token = $request->attributes->get('bitflag.sign_in.device_token');

        return is_string($token) ? $token : null;
    }

    public static function user(Request $request): ?User
    {
        $user = $request->attributes->get(self::ATTRIBUTE);

        return $user instanceof User ? $user : null;
    }
}
