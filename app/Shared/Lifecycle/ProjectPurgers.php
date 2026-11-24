<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use Illuminate\Contracts\Container\Container;

/**
 * The purgers module service providers register. They are resolved from the container when a project is purged.
 */
final class ProjectPurgers
{
    /** @var list<class-string<ProjectPurger>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProjectPurger>  $class
     */
    public function register(string $class): void
    {
        if (! in_array($class, $this->classes, true)) {
            $this->classes[] = $class;
        }
    }

    /**
     * @return list<ProjectPurger>
     */
    public function all(): array
    {
        return array_map(fn (string $class): ProjectPurger => $this->container->make($class), $this->classes);
    }
}
