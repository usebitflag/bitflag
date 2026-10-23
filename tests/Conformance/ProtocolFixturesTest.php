<?php

declare(strict_types=1);

use BitFlag\Testing\Conformance\FixtureRunner;

/*
 * Runs the pinned protocol fixtures through the evaluator package as this application installs it. The release comes
 * from build/protocol/<version>, filled by scripts/fetch-protocol-fixtures.sh, or from BITFLAG_PROTOCOL_DIR. When it
 * is missing the suite fails; it never skips.
 */

function protocol_runner(): FixtureRunner
{
    static $runner = null;

    $directory = getenv('BITFLAG_PROTOCOL_DIR') ?: null;
    if ($directory === null) {
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/protocol.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $directory = dirname(__DIR__, 2).'/build/protocol/'.$lock['version'];
    }
    if (! is_file($directory.'/fixtures/manifest.json')) {
        throw new RuntimeException("The protocol fixtures are missing at $directory. Run scripts/fetch-protocol-fixtures.sh, or set BITFLAG_PROTOCOL_DIR to an extracted release.");
    }

    return $runner ??= new FixtureRunner($directory);
}

dataset('protocol evaluation cases', function () {
    foreach (protocol_runner()->evaluationCaseIds() as $id) {
        yield $id['fixture'].' / '.$id['case'] => [$id['fixture'], $id['case']];
    }
});

dataset('protocol snapshot fixtures', function () {
    foreach (protocol_runner()->snapshotFixtureIds() as $id) {
        yield $id => [$id];
    }
});

it('uses an intact protocol release', function () {
    expect(protocol_runner()->verifyManifest())->toBe([]);
});

it('passes the protocol evaluation fixture', function (string $fixture, string $case) {
    $outcome = protocol_runner()->runEvaluationCase($fixture, $case);

    expect($outcome->failures)->toBe([], $outcome->describe());
})->with('protocol evaluation cases');

it('handles the protocol snapshot fixture', function (string $fixture) {
    $outcome = protocol_runner()->runSnapshotFixture($fixture);

    expect($outcome->failures)->toBe([], $outcome->describe());
})->with('protocol snapshot fixtures');

it('computes every protocol bucket and canonical vector', function () {
    $failed = [];
    foreach ([...protocol_runner()->runBucketVectors(), ...protocol_runner()->runCanonicalVectors()] as $outcome) {
        if (! $outcome->passed()) {
            $failed[] = $outcome->describe();
        }
    }

    expect($failed)->toBe([]);
});
