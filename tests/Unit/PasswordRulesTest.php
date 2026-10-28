<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\CommonPasswords;
use App\Modules\Identity\Domain\PasswordRules;
use App\Modules\Identity\Support\BundledCommonPasswords;

function rules(array $common = ['password1234']): PasswordRules
{
    return new PasswordRules(new class($common) implements CommonPasswords
    {
        public function __construct(private array $list) {}

        public function contains(string $password): bool
        {
            return in_array(mb_strtolower($password), $this->list, true);
        }
    });
}

it('needs twelve characters, not eleven', function () {
    expect(rules()->violations('abcdefghijk'))->toBe([PasswordRules::TOO_SHORT])
        ->and(rules()->violations('abcdefghijkl'))->toBe([]);
});

it('counts characters, not bytes', function () {
    expect(rules()->violations(str_repeat('é', 12)))->toBe([])
        ->and(rules()->violations(str_repeat('é', 11)))->toBe([PasswordRules::TOO_SHORT]);
});

it('stops at 128 characters', function () {
    expect(rules()->violations(str_repeat('a', 128)))->toBe([])
        ->and(rules()->violations(str_repeat('a', 129)))->toBe([PasswordRules::TOO_LONG]);
});

it('rejects a common password in any letter case', function () {
    expect(rules()->violations('PassWord1234'))->toBe([PasswordRules::COMMON]);
});

it('rejects the email and its local part, ignoring case and surrounding space', function (string $password) {
    expect(rules()->violations($password, 'maya.chen.ac@acme.dev'))->toContain(PasswordRules::IS_EMAIL);
})->with(['the email' => ['  Maya.Chen.AC@acme.dev '], 'the local part' => ['MAYA.chen.ac']]);

it('asks for nothing else: no digits or symbols', function () {
    expect(rules()->violations('correct horse battery'))->toBe([]);
});

it('reports every broken rule in a stable order', function () {
    expect(rules(['password'])->violations('password', 'password@acme.dev'))
        ->toBe([PasswordRules::TOO_SHORT, PasswordRules::COMMON, PasswordRules::IS_EMAIL]);
});

it('reads the bundled list, case-insensitively, and the bundled file is lowercase and not empty', function () {
    $path = dirname(__DIR__, 2).'/resources/security/common-passwords.txt';
    $list = new BundledCommonPasswords($path);
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($list->contains('PASSWORD1234'))->toBeTrue()
        ->and($list->contains('correct horse battery'))->toBeFalse()
        ->and(count($lines))->toBeGreaterThan(50)
        ->and(array_filter($lines, fn (string $l) => $l !== mb_strtolower($l)))->toBe([]);
});
