<?php

declare(strict_types=1);

// A credential's secret is shown once, in the response to the request that issues it. Only the credential controllers and
// actions may ask for the plaintext; anywhere else a call to reveal() is how a secret ends up in a log, a job or a page.
// This check reads source text, so it also sees calls that are written but not yet reached by a test.

/**
 * Files that call reveal() from somewhere it is not allowed.
 *
 * @return list<string>
 */
function secret_reveal_violations(string $root): array
{
    $allowed = [
        '#^app/Modules/Credentials/Http/Controllers/#',
        '#^app/Modules/Credentials/Actions/#',
        // The class that holds the secret defines the method.
        '#^app/Modules/Credentials/Data/PlaintextSecret\.php$#',
    ];
    $violations = [];

    if (! is_dir("{$root}/app")) {
        return [];
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root))), '/');

        if (preg_match('/->reveal\(/', (string) file_get_contents($file->getPathname())) !== 1) {
            continue;
        }

        $permitted = false;
        foreach ($allowed as $pattern) {
            $permitted = $permitted || preg_match($pattern, $relative) === 1;
        }

        if (! $permitted) {
            $violations[] = $relative;
        }
    }

    sort($violations);

    return $violations;
}

it('reveals a secret only in the credential controllers and actions', function () {
    expect(secret_reveal_violations(dirname(__DIR__, 2)))->toBe([]);
})->group('arch');
