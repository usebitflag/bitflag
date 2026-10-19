<?php

declare(strict_types=1);

// Actions are the only place that writes. They receive the actor and data as arguments and never reach for
// the session, the current request or the authenticated user.

foreach (bitflag_modules() as $module) {
    $actions = "App\\Modules\\{$module}\\Actions";

    arch("{$module} actions are final classes with a handle method")
        ->expect($actions)
        ->toBeClasses()
        ->toBeFinal()
        ->toHaveMethod('handle');

    arch("{$module} actions do not read the request, session or authenticated user")
        ->expect($actions)
        ->not->toUse([
            'auth', 'request', 'session',
            'Illuminate\Support\Facades\Auth',
            'Illuminate\Support\Facades\Request',
            'Illuminate\Support\Facades\Session',
            'Illuminate\Http\Request',
        ]);
}
