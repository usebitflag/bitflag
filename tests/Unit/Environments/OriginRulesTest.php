<?php

declare(strict_types=1);

use App\Modules\Environments\Domain\OriginRules;
use App\Modules\Environments\Domain\PolicyChange;

describe('origins', function () {
    it('normalizes the scheme and host, the default port and a trailing slash', function (string $input, string $expected) {
        expect(OriginRules::normalize($input))->toBe($expected);
    })->with([
        'upper case host' => ['https://App.Example.COM', 'https://app.example.com'],
        'upper case scheme' => ['HTTPS://app.example.com', 'https://app.example.com'],
        'trailing slash' => ['https://app.example.com/', 'https://app.example.com'],
        'default https port' => ['https://app.example.com:443', 'https://app.example.com'],
        'default http port' => ['http://localhost:80', 'http://localhost'],
        'other port stays' => ['https://app.example.com:8443', 'https://app.example.com:8443'],
        'local http' => ['http://127.0.0.1:3000', 'http://127.0.0.1:3000'],
    ]);

    it('refuses what is not scheme://host[:port]', function (string $input) {
        expect(OriginRules::normalize($input))->toBeNull()
            ->and(OriginRules::problem($input))->toBe(OriginRules::SHAPE);
    })->with([
        'no scheme' => ['app.example.com'],
        'path' => ['https://app.example.com/app'],
        'query' => ['https://app.example.com?x=1'],
        'fragment' => ['https://app.example.com#top'],
        'wildcard' => ['https://*.example.com'],
        'credentials' => ['https://user@example.com'],
        'empty host' => ['https://'],
        'port out of range' => ['https://example.com:70000'],
        'other scheme' => ['ftp://example.com'],
        'double dot' => ['https://a..example.com'],
    ]);

    it('allows plain http only for localhost and 127.0.0.1', function () {
        expect(OriginRules::problem('http://example.com'))->toBe(OriginRules::HTTPS)
            ->and(OriginRules::problem('http://localhost:3000'))->toBeNull()
            ->and(OriginRules::problem('http://127.0.0.1'))->toBeNull()
            ->and(OriginRules::problem('https://example.com'))->toBeNull();
    });

    it('reports the first problem in a list, with its position', function () {
        expect(OriginRules::firstProblem(['https://a.example.com', 'https://b.example.com']))->toBeNull()
            ->and(OriginRules::firstProblem(['https://a.example.com', 'nope']))->toBe([1, OriginRules::SHAPE])
            ->and(OriginRules::firstProblem(['https://a.example.com', 'https://A.example.com/']))->toBe([1, OriginRules::DUPLICATE])
            ->and(OriginRules::firstProblem(array_map(fn (int $i) => "https://h{$i}.example.com", range(1, 21))))->toBe([-1, OriginRules::TOO_MANY])
            ->and(OriginRules::firstProblem(array_map(fn (int $i) => "https://h{$i}.example.com", range(1, 20))))->toBeNull();
    });
});

describe('policy changes', function () {
    it('clears required approvals when protection is off', function () {
        $change = PolicyChange::from(false, 1);

        expect($change->isProtected)->toBeFalse()
            ->and($change->requiredApprovals)->toBe(0)
            ->and($change->requireReason())->toBeFalse();
    });

    it('requires a reason whenever the environment is protected and caps approvals at the launch maximum', function () {
        $change = PolicyChange::from(true, 7);

        expect($change->requireReason())->toBeTrue()
            ->and($change->requiredApprovals)->toBe(PolicyChange::MAX_APPROVALS)
            ->and(PolicyChange::from(true, -2)->requiredApprovals)->toBe(0);
    });

    it('knows when it differs and when it raises approvals', function () {
        $change = PolicyChange::from(true, 1);

        expect($change->differsFrom(true, 1))->toBeFalse()
            ->and($change->differsFrom(false, 0))->toBeTrue()
            ->and($change->raisesApprovalsAbove(0))->toBeTrue()
            ->and($change->raisesApprovalsAbove(1))->toBeFalse()
            ->and(PolicyChange::from(true, 0)->raisesApprovalsAbove(1))->toBeFalse();
    });
});
