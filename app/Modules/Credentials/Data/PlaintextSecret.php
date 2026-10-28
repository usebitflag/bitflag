<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Data;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * A credential's secret, held only until the response that shows it. It masks itself everywhere it could leak: string
 * conversion, JSON, debug output and serialization. Only `reveal()` returns the value, and an architecture test limits
 * who may call it to the credential controllers and actions.
 */
final class PlaintextSecret implements JsonSerializable, Stringable
{
    private const MASK = '[secret hidden]';

    public function __construct(private readonly string $value) {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret is never serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A secret is never serialized.');
    }
}
