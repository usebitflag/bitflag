<?php

declare(strict_types=1);

use App\Modules\Organizations\Domain\AllowedDomains;
use App\Modules\Organizations\Domain\SlugRules;

it('accepts a lower-case name of 3 to 40 characters that starts with a letter', function (string $slug) {
    expect(SlugRules::problem($slug))->toBeNull();
})->with(['acme', 'acme-corp', 'a1b', 'acme-'.'x2', str_repeat('a', 40)]);

it('refuses a name that is too short or too long', function (string $slug) {
    expect(SlugRules::problem($slug))->toBe(SlugRules::TOO_SHORT_OR_LONG);
})->with(['', 'ab', str_repeat('a', 41)]);

it('refuses a name that does not start with a letter or has other characters', function (string $slug) {
    expect(SlugRules::problem($slug))->toBe(SlugRules::BAD_CHARACTERS);
})->with(['1acme', '-acme', 'Acme', 'ac me', 'acme_corp', 'acmé', 'acme.dev']);

it('refuses every reserved word long enough to be a name', function (string $word) {
    expect(SlugRules::problem($word))->toBe(SlugRules::TAKEN_WORD);
})->with(fn () => array_values(array_filter(SlugRules::RESERVED, fn (string $word) => strlen($word) >= 3)));

it('refuses anything starting with bf-', function () {
    expect(SlugRules::problem('bf-labs'))->toBe(SlugRules::TAKEN_WORD);
});

it('keeps the words the first milestone reserved', function () {
    foreach (['up', 'horizon', 'signin', 'signout', 'login', 'logout', 'scope', 'no-access'] as $word) {
        expect(SlugRules::RESERVED)->toContain($word);
    }
});

it('makes a suggestion from a name', function (string $name, string $slug) {
    expect(SlugRules::suggest($name))->toBe($slug);
})->with([
    ['Acme Corp', 'acme-corp'],
    ['  Acme, Inc.  ', 'acme-inc'],
    ['42 Labs', 'labs'],
    ['Zürich Team', 'z-rich-team'],
    ['!!!', ''],
]);

it('builds a route pattern that matches valid names and excludes reserved ones', function () {
    $pattern = '#^'.SlugRules::routePattern().'$#';

    expect(preg_match($pattern, 'acme'))->toBe(1)
        ->and(preg_match($pattern, 'signup'))->toBe(0)
        ->and(preg_match($pattern, 'account'))->toBe(0)
        ->and(preg_match($pattern, 'bf-labs'))->toBe(0)
        ->and(preg_match($pattern, 'signup-team'))->toBe(1)
        ->and(preg_match($pattern, '1acme'))->toBe(0);
});

it('cleans a list of allowed domains', function () {
    $result = AllowedDomains::clean(['@Acme.dev', ' acme.dev ', 'Example.CO.UK', '', 'not a domain', 'nodot', 'a..b.com']);

    expect($result['domains'])->toBe(['acme.dev', 'example.co.uk'])
        ->and($result['invalid'])->toBe(['not a domain', 'nodot', 'a..b.com']);
});

it('allows any address when no domain is listed, and otherwise only those domains', function () {
    expect(AllowedDomains::permits([], 'x@anything.org'))->toBeTrue()
        ->and(AllowedDomains::permits(['acme.dev'], 'Maya@ACME.dev'))->toBeTrue()
        ->and(AllowedDomains::permits(['acme.dev'], 'maya@sub.acme.dev'))->toBeFalse()
        ->and(AllowedDomains::permits(['acme.dev'], 'maya@acme.dev.evil.com'))->toBeFalse()
        ->and(AllowedDomains::permits(['acme.dev'], 'no-at-sign'))->toBeFalse();
});
