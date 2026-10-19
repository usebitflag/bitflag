<?php

declare(strict_types=1);

// Shared holds stable primitives. Product logic has a named module owner, so Shared never depends on one.

arch('shared code does not depend on modules or the admin console')
    ->expect('App\Shared')
    ->not->toUse(['App\Modules', 'App\Admin']);
