<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use InvalidArgumentException;

/**
 * Registry of audit action names. Names are `{object}.{verb}` in past tense, for example `flag.created`.
 * Module service providers register the names their actions record, so a typo fails instead of being stored.
 */
final class AuditCatalog
{
    private const PATTERN = '/^[a-z_]+\.[a-z_]+$/';

    /** @var array<string, true> */
    private array $actions = [];

    public function register(string ...$actions): void
    {
        foreach ($actions as $action) {
            if (preg_match(self::PATTERN, $action) !== 1) {
                throw new InvalidArgumentException("Audit action \"{$action}\" must look like object.verb.");
            }

            $this->actions[$action] = true;
        }
    }

    public function has(string $action): bool
    {
        return isset($this->actions[$action]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $actions = array_keys($this->actions);
        sort($actions);

        return $actions;
    }
}
