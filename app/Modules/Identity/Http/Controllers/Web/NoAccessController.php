<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Shown to a signed-in person who belongs to no project yet.
 */
final class NoAccessController
{
    public function show(): Response
    {
        return Inertia::render('account/NoAccess');
    }
}
