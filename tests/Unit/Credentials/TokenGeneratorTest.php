<?php

declare(strict_types=1);

use App\Modules\Credentials\Data\PlaintextSecret;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\EnvironmentTag;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Support\RedactSecrets;
use Monolog\Level;
use Monolog\LogRecord;

describe('generating a secret', function () {
    it('has the format of its type', function (CredentialType $type, string $pattern) {
        $token = (new TokenGenerator)->generate($type, 'prod');

        expect($token->secret)->toMatch($pattern)
            ->and($token->last4)->toBe(substr($token->secret, -4))
            ->and(str_starts_with($token->secret, $token->displayPrefix))->toBeTrue()
            ->and(TokenGenerator::parse($token->secret)?->type)->toBe($type);
    })->with([
        'server' => [CredentialType::Server, '/^bf_srv_prod_[A-Za-z0-9]{36}$/'],
        'browser' => [CredentialType::Browser, '/^bf_pub_prod_[A-Za-z0-9]{36}$/'],
        'management' => [CredentialType::Management, '/^bf_mgt_prod_[A-Za-z0-9]{36}$/'],
        'personal' => [CredentialType::Personal, '/^bf_pat_[A-Za-z0-9]{36}$/'],
    ]);

    it('makes a different secret every time', function () {
        $generator = new TokenGenerator;

        expect($generator->generate(CredentialType::Server, 'dev')->secret)->not->toBe($generator->generate(CredentialType::Server, 'dev')->secret);
    });

    it('draws the random part without a visible bias', function () {
        $counts = array_fill_keys(str_split(TokenGenerator::ALPHABET), 0);
        $total = 0;

        while ($total < 620_000) {
            foreach (str_split(TokenGenerator::randomBase62(1000)) as $char) {
                $counts[$char]++;
                $total++;
            }
        }

        // Each of the 62 characters should appear about 1/62 of the time; modulo bias would show as the first 8 high.
        $expected = $total / 62;
        $worst = max(array_map(fn (int $count) => abs($count - $expected) / $expected, $counts));

        expect($worst)->toBeLessThan(0.06);
    });
});

describe('parsing a presented secret', function () {
    it('accepts what it generated and gives the type and tag', function () {
        $token = (new TokenGenerator)->generate(CredentialType::Browser, 'stg');
        $parsed = TokenGenerator::parse($token->secret);

        expect($parsed?->type)->toBe(CredentialType::Browser)->and($parsed?->tag)->toBe('stg');
    });

    it('rejects a one-character change anywhere in the secret', function () {
        $secret = (new TokenGenerator)->generate(CredentialType::Server, 'dev')->secret;
        $start = strlen('bf_srv_dev_');

        for ($i = $start; $i < strlen($secret); $i++) {
            $changed = $secret;
            $changed[$i] = $changed[$i] === 'a' ? 'b' : 'a';

            expect(TokenGenerator::parse($changed))->toBeNull();
        }
    });

    it('rejects what is not shaped like a credential', function (string $input) {
        expect(TokenGenerator::parse($input))->toBeNull();
    })->with([
        'empty' => [''],
        'wrong prefix' => ['sk_live_abc'],
        'short' => ['bf_srv_dev_abc'],
        'no tag' => ['bf_srv_'.str_repeat('a', 36)],
        'long tag' => ['bf_srv_toolong_'.str_repeat('a', 36)],
        'upper case tag' => ['bf_srv_DEV_'.str_repeat('a', 36)],
        'personal with a tag' => ['bf_pat_dev_'.str_repeat('a', 36)],
        'trailing space' => ['bf_pat_'.str_repeat('a', 36).' '],
    ]);
});

it('tags environments', function (string $key, string $tag) {
    expect(EnvironmentTag::for($key))->toBe($tag);
})->with([
    ['development', 'dev'],
    ['staging', 'stg'],
    ['production', 'prod'],
    ['qa', 'qa'],
    ['pre-production', 'prep'],
    ['eu-west-1', 'euwe'],
]);

describe('keeping a secret out of sight', function () {
    it('masks itself everywhere but reveal()', function () {
        $secret = new PlaintextSecret('bf_srv_dev_SECRET');

        expect((string) $secret)->not->toContain('SECRET')
            ->and(json_encode(['secret' => $secret]))->not->toContain('SECRET')
            ->and(print_r($secret, true))->not->toContain('SECRET')
            ->and(fn () => serialize($secret))->toThrow(LogicException::class)
            ->and($secret->reveal())->toBe('bf_srv_dev_SECRET');
    });

    it('masks credentials in log records', function () {
        $secret = (new TokenGenerator)->generate(CredentialType::Server, 'dev')->secret;
        $personal = (new TokenGenerator)->generate(CredentialType::Personal)->secret;
        $record = new LogRecord(new DateTimeImmutable, 'test', Level::Error, "Failed with {$secret}", ['token' => $personal, 'nested' => ['x' => "Bearer {$secret}"], 'exception' => new RuntimeException("bad {$secret}")], ['raw' => $secret]);

        $masked = (new RedactSecrets)($record);
        $everything = json_encode([$masked->message, $masked->context, $masked->extra]);

        expect($everything)->not->toContain(TokenGenerator::randomPart($secret))
            ->and($everything)->not->toContain(TokenGenerator::randomPart($personal))
            ->and($masked->message)->toContain(RedactSecrets::MASK);
    });
});
