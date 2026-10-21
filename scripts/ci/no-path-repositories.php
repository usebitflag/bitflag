<?php

declare(strict_types=1);

// Fails when composer.json or composer.lock depends on a local path. The application must build from
// released packages only, never from a sibling checkout.

$root = dirname(__DIR__, 2);
$problems = [];

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

foreach ((array) ($composer['repositories'] ?? []) as $name => $repository) {
    if (is_array($repository) && ($repository['type'] ?? null) === 'path') {
        $problems[] = "composer.json repository \"{$name}\" is a path repository";
    }
}

$lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

foreach (['packages', 'packages-dev'] as $section) {
    foreach ((array) ($lock[$section] ?? []) as $package) {
        $type = $package['dist']['type'] ?? null;
        $sourceType = $package['source']['type'] ?? null;

        if ($type === 'path' || $sourceType === 'path') {
            $problems[] = "composer.lock package \"{$package['name']}\" comes from a path";
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode(PHP_EOL, $problems).PHP_EOL);
    exit(1);
}

echo "No path repositories.\n";
