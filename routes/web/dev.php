<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The component gallery exists in local development and tests only, and is absent everywhere else.
if (app()->environment(['local', 'testing'])) {
    Route::get('/_dev/ui', fn () => Inertia::render('dev/UiGallery'))->name('dev.ui');
}
