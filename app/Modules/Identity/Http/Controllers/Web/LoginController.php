<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The sign-in screen. The submission itself is handled by Fortify's authentication pipeline.
 */
final class LoginController
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }
}
