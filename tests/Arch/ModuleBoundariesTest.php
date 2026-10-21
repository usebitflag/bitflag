<?php

declare(strict_types=1);

// A module's internals are private to the module. Other modules use its Actions, Data, Queries, Events and
// Domain\Exceptions only. Filament resources in App\Admin may use models, and migrations and seeders in
// database/ and config/ may use anything.

/**
 * Every reference from application code to another module's private layers.
 *
 * @return list<string>
 */
function module_boundary_violations(string $root): array
{
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');

        if (preg_match('#^(database|tests|config)/#', $relative) === 1) {
            continue;
        }

        $ownModule = preg_match('#^app/Modules/([A-Za-z]+)/#', $relative, $own) === 1 ? $own[1] : null;
        $isAdmin = str_starts_with($relative, 'app/Admin/');
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)\\\\(Models|Domain|Http|Policies|Jobs)\\\\([A-Za-z\\\\]+)/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [$reference, $module, $layer, $rest]) {
            if ($module === $ownModule) {
                continue;
            }
            if ($layer === 'Domain' && str_starts_with($rest, 'Exceptions\\')) {
                continue;
            }
            if ($layer === 'Models' && $isAdmin) {
                continue;
            }

            $violations[] = "{$relative} uses {$reference}";
        }
    }

    sort($violations);

    return $violations;
}

it('keeps every module private layer private', function () {
    expect(module_boundary_violations(dirname(__DIR__, 2)))->toBe([]);
})->group('arch');

it('finds a cross-module reference to a private layer', function () {
    $dir = sys_get_temp_dir().'/bf-boundary-'.bin2hex(random_bytes(4));
    mkdir($dir.'/app/Modules/Billing/Actions', 0777, true);
    file_put_contents($dir.'/app/Modules/Billing/Actions/Bad.php', '<?php use App\Modules\Flags\Models\Flag;');
    file_put_contents($dir.'/app/Modules/Billing/Actions/Ok.php', '<?php use App\Modules\Flags\Domain\Exceptions\NotFound; use App\Modules\Billing\Models\Plan;');

    expect(module_boundary_violations($dir))->toBe(['app/Modules/Billing/Actions/Bad.php uses App\Modules\Flags\Models\Flag']);

    unlink($dir.'/app/Modules/Billing/Actions/Bad.php');
    unlink($dir.'/app/Modules/Billing/Actions/Ok.php');
    rmdir($dir.'/app/Modules/Billing/Actions');
    rmdir($dir.'/app/Modules/Billing');
    rmdir($dir.'/app/Modules');
    rmdir($dir.'/app');
    rmdir($dir);
})->group('arch');
