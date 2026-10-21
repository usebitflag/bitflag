<?php

declare(strict_types=1);

use App\Shared\Navigation\NavigationEntry;
use App\Shared\Navigation\NavigationGroup;
use App\Shared\Navigation\PortalNavigation;

function entry(string $key, NavigationGroup $group, int $order = 10): NavigationEntry
{
    return new NavigationEntry($key, ucfirst($key), 'house', $group, $order, "{$key}.index");
}

it('is a shared singleton', function () {
    expect(app(PortalNavigation::class))->toBe(app(PortalNavigation::class));
});

it('orders entries by group, then order, then key', function () {
    $navigation = new PortalNavigation;
    $navigation->register(entry('settings', NavigationGroup::Administration));
    $navigation->register(entry('segments', NavigationGroup::Configuration, 20));
    $navigation->register(entry('overview', NavigationGroup::Project));
    $navigation->register(entry('flags', NavigationGroup::Configuration, 10));
    $navigation->register(entry('audit', NavigationGroup::Administration));

    expect(array_map(static fn (NavigationEntry $e): string => $e->key, $navigation->entries()))
        ->toBe(['overview', 'flags', 'segments', 'audit', 'settings']);
});

it('rejects a duplicate key', function () {
    $navigation = new PortalNavigation;
    $navigation->register(entry('flags', NavigationGroup::Configuration));

    $navigation->register(entry('flags', NavigationGroup::Configuration));
})->throws(InvalidArgumentException::class, 'already registered');

it('lists the groups in sidebar order', function () {
    expect(array_map(static fn (NavigationGroup $g): string => $g->label(), NavigationGroup::cases()))
        ->toBe(['Project', 'Configuration', 'Releases', 'Insights', 'Administration']);
});
