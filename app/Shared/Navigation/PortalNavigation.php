<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

use InvalidArgumentException;

/**
 * Registry of sidebar entries. Module service providers add their entries; the sidebar reads them in a
 * fixed order: group, then order key, then entry key.
 */
final class PortalNavigation
{
    /** @var array<string, NavigationEntry> */
    private array $entries = [];

    public function register(NavigationEntry $entry): void
    {
        if (isset($this->entries[$entry->key])) {
            throw new InvalidArgumentException("Navigation entry \"{$entry->key}\" is already registered.");
        }

        $this->entries[$entry->key] = $entry;
    }

    /**
     * @return list<NavigationEntry>
     */
    public function entries(): array
    {
        $entries = array_values($this->entries);

        usort($entries, static fn (NavigationEntry $a, NavigationEntry $b): int => [
            $a->group->position(), $a->order, $a->key,
        ] <=> [
            $b->group->position(), $b->order, $b->key,
        ]);

        return $entries;
    }
}
