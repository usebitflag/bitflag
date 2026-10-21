<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Cleans audit details before they are stored. Keys that can carry secrets, key material or subject data are dropped at
 * any depth, and long strings are shortened. Redaction is a safety net: callers should not pass such values at all.
 */
final class Redactor
{
    public const MAX_STRING_LENGTH = 500;

    private const FORBIDDEN_KEY = '/password|secret|token|plaintext|key_value|context|attributes|authorization/i';

    /**
     * @param  array<array-key, mixed>  $details
     * @return array<array-key, mixed>
     */
    public function redact(array $details): array
    {
        $clean = [];

        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY, $key) === 1) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => $this->truncate($value),
                default => $value,
            };
        }

        return $clean;
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH - 1).'…';
    }
}
