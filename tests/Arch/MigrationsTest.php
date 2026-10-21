<?php

declare(strict_types=1);

// A column that holds one of a fixed set of values is a string column with a backed PHP enum cast on the model, never
// a database ENUM or SET. The allowed values then live in one place, and adding a value needs no table change.
//
// This check reads source text, so it sees migrations as written.

/**
 * Migration lines that declare a database enum or set column.
 *
 * @return list<string>
 */
function migration_enum_violations(string $directory): array
{
    $violations = [];

    foreach (glob("{$directory}/*.php") ?: [] as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match('/->(enum|set)\(/', $line) === 1) {
                $violations[] = basename($file).':'.($number + 1);
            }
        }
    }

    sort($violations);

    return $violations;
}

it('declares fixed-value columns as strings, never as database enums', function () {
    expect(migration_enum_violations(dirname(__DIR__, 2).'/database/migrations'))->toBe([]);
})->group('arch');

it('finds enum and set columns in a migration', function () {
    $dir = sys_get_temp_dir().'/bf-migrations-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/a.php', "<?php\n\$table->string('status', 32);\n\$table->enum('kind', ['a']);\n\$table->set('flags', ['x']);\n");

    expect(migration_enum_violations($dir))->toBe(['a.php:3', 'a.php:4']);
})->group('arch');
