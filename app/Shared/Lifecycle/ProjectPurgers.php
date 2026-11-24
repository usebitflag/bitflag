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
        $purgers = [];

        foreach ($this->classes as $class) {
            $purger = $this->container->make($class);

            if ($purger instanceof ProjectPurger) {
                $purgers[] = $purger;
            }
        }

        return $purgers;
    }
}
