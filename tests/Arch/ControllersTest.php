<?php

declare(strict_types=1);

// Controllers validate the request, call an Action or a Query, and format the result. They hold no business rules,
// no persistence and no direct database access.
//
// Each rule is declared once per namespace: an architecture expectation over several namespaces at once does not
// report violations reliably.

$controllerNamespaces = array_map(
    static fn (string $module): string => "App\\Modules\\{$module}\\Http\\Controllers",
    bitflag_modules(),
);
$controllerNamespaces[] = 'App\Http\Controllers';

foreach ($controllerNamespaces as $namespace) {
    arch("{$namespace} does not touch the database or models directly")
        ->expect($namespace)
        ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Eloquent\Model', 'App\Models']);
}

foreach (bitflag_modules() as $module) {
    arch("{$module} controllers use no models or domain classes")
        ->expect("App\\Modules\\{$module}\\Http\\Controllers")
        ->not->toUse(["App\\Modules\\{$module}\\Models", "App\\Modules\\{$module}\\Domain"])
        ->ignoring("App\\Modules\\{$module}\\Domain\\Exceptions");
}
