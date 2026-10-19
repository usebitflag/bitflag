<?php

declare(strict_types=1);

// Domain classes hold rules and state machines. They know nothing about HTTP, facades, Inertia or global helpers.

foreach (bitflag_modules() as $module) {
    arch("{$module} domain classes are free of framework HTTP types and global helpers")
        ->expect("App\\Modules\\{$module}\\Domain")
        ->not->toUse([
            'Illuminate\Http',
            'Illuminate\Support\Facades',
            'Inertia',
            'App\Http',
            'auth', 'request', 'session', 'app', 'config', 'cache', 'now', 'today', 'env',
        ])
        ->ignoring("App\\Modules\\{$module}\\Domain\\Exceptions");
}
