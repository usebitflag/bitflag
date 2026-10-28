<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Support\OAuth\OAuthProviders;

/**
 * The providers the sign-in and sign-up pages offer: only those this installation has credentials for.
 */
final class SocialProviderOptions
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public static function from(OAuthProviders $providers): array
    {
        return array_map(
            static fn ($provider): array => ['key' => $provider->value, 'label' => $provider->label()],
            $providers->available(),
        );
    }
}
