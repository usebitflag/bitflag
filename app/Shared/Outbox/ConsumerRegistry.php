<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The consumers module service providers register. They are resolved from the container on first use.
 */
final class ConsumerRegistry
{
    /** @var array<string, class-string<OutboxConsumer>> */
    private array $classes = [];

    /** @var array<string, OutboxConsumer> */
    private array $instances = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<OutboxConsumer>  $class
     */
    public function register(string $class): void
    {
        $name = $this->resolve($class)->name();

        if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
            throw new InvalidArgumentException("Outbox consumer name \"{$name}\" is already used by {$this->classes[$name]}.");
        }

        $this->classes[$name] = $class;
    }

    /**
     * Every consumer that handles the message type.
     *
     * @return list<OutboxConsumer>
     */
    public function consumersFor(string $type): array
    {
        $matching = [];

        foreach (array_keys($this->classes) as $name) {
            $consumer = $this->find($name);

            if ($consumer !== null && in_array($type, $consumer->handles(), true)) {
                $matching[] = $consumer;
            }
        }

        return $matching;
    }

    public function find(string $name): ?OutboxConsumer
    {
        if (! isset($this->classes[$name])) {
            return null;
        }

        return $this->instances[$name] ??= $this->resolve($this->classes[$name]);
    }

    /**
     * @param  class-string<OutboxConsumer>  $class
     */
    private function resolve(string $class): OutboxConsumer
    {
        $consumer = $this->container->make($class);

        if (! $consumer instanceof OutboxConsumer) {
            throw new InvalidArgumentException("{$class} must implement ".OutboxConsumer::class.'.');
        }

        return $consumer;
    }
}
