<?php

declare(strict_types=1);

namespace App\Shared\Persistence;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Audit entries and outbox messages must commit or roll back together with the change they describe. This guard throws
 * when they are written outside a transaction, where a crash could leave one without the other.
 */
final class TransactionGuard
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function assertInTransaction(string $what): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException("{$what} must be written inside the transaction of the change it records.");
        }
    }
}
