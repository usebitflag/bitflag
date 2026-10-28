<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use Illuminate\Contracts\Container\Container;

/**
 * The export sections module service providers register, in the order they were registered.
 */
final class ProjectExportSections
{
    /** @var list<class-string<ProjectExportSection>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<ProjectExportSection>  $class
     */
    public function register(string $class): void
    {
        if (! in_array($class, $this->classes, true)) {
            $this->classes[] = $class;
        }
    }

    /**
     * @return list<ProjectExportSection>
     */
    public function all(): array
    {
        $sections = [];

        foreach ($this->classes as $class) {
            $section = $this->container->make($class);

            if ($section instanceof ProjectExportSection) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
