<?php

use Illuminate\Support\Facades\Route;

// Portal routes are bound to the app host. Each module adds its routes in routes/web/{module}.php.
Route::domain(config('bitflag.hosts.app'))->group(function (): void {
    foreach (glob(__DIR__.'/web/*.php') ?: [] as $file) {
        require $file;
    }
});
