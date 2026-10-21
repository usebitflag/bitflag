<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

use Closure;

/**
 * One sidebar item contributed by a module. Items appear only once the module registers them,
 * so the sidebar never links to a page that does not exist.
 */
final readonly class NavigationEntry
{
    /**
     * @param  string  $key  Stable identifier, unique across modules.
     * @param  string  $icon  Phosphor icon name.
     * @param  int  $order  Position inside the group; lower comes first.
     * @param  string  $routeName  Named route of the page, resolved with the current scope.
     * @param  (Closure(): (int|null))|null  $count  Optional count shown as a pill; evaluated lazily.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public NavigationGroup $group,
        public int $order,
        public string $routeName,
        public ?Closure $count = null,
    ) {}
}
