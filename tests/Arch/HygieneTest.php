<?php

declare(strict_types=1);

// Each rule is declared once per namespace: an architecture expectation over several namespaces at once does not
// report violations reliably.

foreach (['App', 'Database'] as $namespace) {
    arch("{$namespace} leaves no debugging calls behind")
        ->expect($namespace)
        ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export']);

    arch("{$namespace} reads environment variables only through configuration")
        ->expect($namespace)
        ->not->toUse('env');
}
