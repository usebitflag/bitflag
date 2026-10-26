<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Support\OAuth\OAuthProviders;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sign-in screen. The submission itself is handled by Fortify's authentication pipeline.
 */
final class LoginController
{
    public function create(OAuthProviders $providers): Response
    {
        return Inertia::render('auth/SignIn', ['providers' => SocialProviderOptions::from($providers)]);
    }
}
