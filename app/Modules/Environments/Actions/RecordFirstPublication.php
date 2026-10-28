<?php

declare(strict_types=1);

namespace App\Modules\Environments\Actions;

use App\Modules\Environments\Models\Environment;
use App\Shared\Persistence\TransactionGuard;
use Psr\Clock\ClockInterface;

/**
 * Notes that an environment has published a revision, which fixes its key. System-only: the publication transaction calls it.
 */
final class RecordFirstPublication
{
    public function __construct(
        private readonly TransactionGuard $transactions,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $environmentId): void
    {
        $this->transactions->assertInTransaction('Recording a first publication');

        Environment::query()->whereKey($environmentId)->whereNull('first_published_at')->update(['first_published_at' => $this->clock->now()]);
    }
}
