<?php

declare(strict_types=1);

// The application uses the evaluator package only through App\Shared\Evaluation, the Flags module and the Publishing
// module, and never reaches into its internals, client or transport.
//
// This check reads source text instead of loaded classes, so it also holds before the package is installed.

/**
 * Every reference to the BitFlag evaluator package that the boundary does not allow.
 *
 * @return list<string>
 */
function evaluator_boundary_violations(string $root): array
{
    $allowedRoots = ['app/Shared/Evaluation/', 'app/Modules/Flags/', 'app/Modules/Publishing/'];
    $forbidden = ['Evaluation\\Internal', 'Protocol\\Internal', 'Client', 'Transport', 'Cache', 'Telemetry'];
    $violations = [];

    foreach (['app', 'database', 'bootstrap', 'routes', 'config'] as $directory) {
        if (! is_dir("{$root}/{$directory}")) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root))), '/');
            $source = (string) file_get_contents($file->getPathname());

            preg_match_all('/(?<![A-Za-z0-9_])BitFlag\\\\([A-Za-z0-9_\\\\]+)/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [$reference, $rest]) {
                foreach ($forbidden as $namespace) {
                    if ($rest === $namespace || str_starts_with($rest, $namespace.'\\')) {
                        $violations[] = "{$relative} uses {$reference}";

                        continue 2;
                    }
                }

                $allowed = false;
                foreach ($allowedRoots as $allowedRoot) {
                    $allowed = $allowed || str_starts_with($relative, $allowedRoot);
                }

                if (! $allowed) {
                    $violations[] = "{$relative} uses {$reference}";
                }
            }
        }
    }

    sort($violations);

    return $violations;
}

it('keeps the evaluator package behind its gateway', function () {
    expect(evaluator_boundary_violations(dirname(__DIR__, 2)))->toBe([]);
})->group('arch');

it('finds evaluator use outside the gateway and use of its internals', function () {
    $dir = sys_get_temp_dir().'/bf-evaluator-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app/Modules/Billing/Actions', 0777, true);
    mkdir($dir.'/app/Modules/Flags/Actions', 0777, true);
    file_put_contents($dir.'/app/Modules/Billing/Actions/Outside.php', '<?php use BitFlag\Evaluation\Evaluator;');
    file_put_contents($dir.'/app/Modules/Flags/Actions/Internals.php', '<?php use BitFlag\Evaluation\Internal\Hasher;');
    file_put_contents($dir.'/app/Modules/Flags/Actions/Fine.php', '<?php use BitFlag\Evaluation\Evaluator;');

    expect(evaluator_boundary_violations($dir))->toBe([
        'app/Modules/Billing/Actions/Outside.php uses BitFlag\Evaluation\Evaluator',
        'app/Modules/Flags/Actions/Internals.php uses BitFlag\Evaluation\Internal\Hasher',
    ]);

    foreach (['Billing/Actions/Outside.php', 'Flags/Actions/Internals.php', 'Flags/Actions/Fine.php'] as $path) {
        unlink($dir.'/app/Modules/'.$path);
    }
    foreach (['Billing/Actions', 'Billing', 'Flags/Actions', 'Flags'] as $path) {
        rmdir($dir.'/app/Modules/'.$path);
    }
    rmdir($dir.'/app/Modules');
    rmdir($dir.'/app');
    rmdir($dir);
})->group('arch');
