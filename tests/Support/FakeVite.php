<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * A Vite stand-in for tests: the @vite directive renders nothing, so no build manifest is needed, while the CSP nonce
 * and hot-file behavior of the real class stay available.
 */
final class FakeVite extends Vite
{
    public static function install(): void
    {
        app()->instance(Vite::class, new self);
        \Illuminate\Support\Facades\Vite::clearResolvedInstance(Vite::class);
    }

    /**
     * @param  string|string[]  $entrypoints
     * @param  string|null  $buildDirectory
     */
    public function __invoke($entrypoints, $buildDirectory = null): HtmlString
    {
        return new HtmlString('');
    }
}
