<?php

declare(strict_types=1);

// Tenant scoping can be switched off only where cross-organization reads are the job: the tenancy code itself, the
// outbox and idempotency maintenance, the admin console, and the Queries that resolve a person's portal scope and
// capabilities. A bypass anywhere else is how one customer's data ends up in another's response.
//
// This check reads source text, so it also sees calls that are written but not yet reached by a test.

/**
 * Files that call the tenant-scope bypass from somewhere it is not allowed.
 *
 * @return list<string>
 */
function tenancy_bypass_violations(string $root): array
{
    $allowed = [
        '#^app/Shared/Tenancy/#',
        '#^app/Shared/Outbox/#',
        '#^app/Shared/Idempotency/#',
        '#^app/Admin/#',
        '#^app/Modules/[A-Za-z]+/Queries/#',
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

        if (preg_match('/crossTenant\(|withoutScoping\(/', (string) file_get_contents($file->getPathname())) !== 1) {
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

it('switches tenant scoping off only where that is the job', function () {
    expect(tenancy_bypass_violations(dirname(__DIR__, 2)))->toBe([]);
})->group('arch');

it('finds a bypass outside the allowed places', function () {
    $dir = sys_get_temp_dir().'/bf-bypass-'.bin2hex(random_bytes(4));
    foreach (['app/Modules/Billing/Actions', 'app/Modules/Billing/Queries', 'app/Shared/Outbox', 'app/Http/Controllers'] as $path) {
        mkdir("{$dir}/{$path}", 0777, true);
    }
    file_put_contents("{$dir}/app/Modules/Billing/Actions/Sneaky.php", '<?php Tenancy::crossTenant("x", fn () => 1);');
    file_put_contents("{$dir}/app/Http/Controllers/Direct.php", '<?php app(TenantContext::class)->withoutScoping(fn () => 1);');
    file_put_contents("{$dir}/app/Modules/Billing/Queries/Fine.php", '<?php Tenancy::crossTenant("x", fn () => 1);');
    file_put_contents("{$dir}/app/Shared/Outbox/AlsoFine.php", '<?php Tenancy::crossTenant("x", fn () => 1);');

    expect(tenancy_bypass_violations($dir))->toBe([
        'app/Http/Controllers/Direct.php',
        'app/Modules/Billing/Actions/Sneaky.php',
    ]);
})->group('arch');
