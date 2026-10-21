<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Shared\Errors\ApplicationException;

/**
 * The record changed since the caller read it. The caller should reload and reapply their edit.
 */
final class VersionConflict extends ApplicationException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $currentVersion)
    {
        parent::__construct('The record changed after you opened it.');
    }

    public function errorCode(): string
    {
        return 'VERSION_CONFLICT';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['expectedVersion' => $this->expectedVersion, 'currentVersion' => $this->currentVersion];
    }
}
