<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Support\Queries\ActiveStaff;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * The Horizon dashboard is for active staff only, in every environment. It is served on the admin host and reads the
     * staff session, so a customer session never reaches it.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn (): bool => $this->app->make(ActiveStaff::class)->isSignedIn());
    }
}
