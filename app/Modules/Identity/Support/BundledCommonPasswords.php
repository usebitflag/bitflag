<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\CommonPasswords;

/**
 * Reads resources/security/common-passwords.txt: one lowercase password per line, compared case-insensitively. The file
 * is replaced with a longer list of common and breached passwords with each release.
 */
final class BundledCommonPasswords implements CommonPasswords
{
    /** @var array<string, true>|null */
    private ?array $passwords = null;

    public function __construct(private readonly string $path) {}

    public function contains(string $password): bool
    {
        return isset($this->load()[mb_strtolower($password)]);
    }

    /**
     * @return array<string, true>
     */
    private function load(): array
    {
        if ($this->passwords !== null) {
            return $this->passwords;
        }

        $this->passwords = [];

        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $this->passwords[mb_strtolower(trim($line))] = true;
        }

        return $this->passwords;
    }
}
