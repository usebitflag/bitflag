<?php

declare(strict_types=1);

use BitFlag\Evaluation\Evaluator;
use BitFlag\Protocol\ProtocolVersion;

/**
 * @return array<string, mixed>
 */
function json_file(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

it('pins the same protocol release as the evaluator package it installs', function () {
    $lock = json_file(dirname(__DIR__, 2).'/protocol.lock.json');
    $package = json_file(dirname(__DIR__, 2).'/vendor/bitflag/bitflag-php/composer.json');
    $extra = $package['extra'] ?? [];
    $packageProtocol = is_array($extra) && is_array($extra['bitflag'] ?? null) ? ($extra['bitflag']['protocol'] ?? null) : null;

    expect($lock['version'])->toBe($packageProtocol);
});

it('requires one exact release of the evaluator package, installed from a VCS repository', function () {
    $composer = json_file(dirname(__DIR__, 2).'/composer.json');
    $require = $composer['require'] ?? [];
    $version = is_array($require) ? ($require['bitflag/bitflag-php'] ?? '') : '';

    expect($version)->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/')
        ->and(json_encode($composer['repositories'] ?? [], JSON_UNESCAPED_SLASHES))->toContain('usebitflag/bitflag-php');
});

it('has the evaluator package and the protocol version it implements', function () {
    expect(class_exists(Evaluator::class))->toBeTrue()
        ->and(ProtocolVersion::MAJOR)->toBe(1);
});

it('pins the protocol release by checksum once it is published', function () {
    $lock = json_file(dirname(__DIR__, 2).'/protocol.lock.json');
    $sha = $lock['sha256'] ?? null;

    expect($sha === null || (is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) === 1))->toBeTrue()
        ->and($lock['asset'])->toBe('bitflag-protocol-'.$lock['version'].'.tar.gz');
});
