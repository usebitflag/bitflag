<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * The providers a person can sign in with besides a password. The stored value is the provider's name in the URL. The order is the order of the buttons.
 */
enum SocialProvider: string
{
    case GitHub = 'github';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
        };
    }
}
