<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

// Portal routes are bound to the app host. Each module adds its routes in routes/web/{module}.php.
Route::domain(Config::string('bitflag.hosts.app'))->group(function (): void {
    foreach (glob(__DIR__.'/web/*.php') ?: [] as $file) {
        require $file;
    }
});
