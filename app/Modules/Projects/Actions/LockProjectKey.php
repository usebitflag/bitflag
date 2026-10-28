<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Models\Project;
use App\Shared\Persistence\TransactionGuard;
use Psr\Clock\ClockInterface;

/**
 * Fixes a project's key. The key locks when the first credential is issued or the first revision is published, because SDKs
 * and the CLI name the project by it. System-only: issuing a credential and publishing call it inside their own transaction.
 */
final class LockProjectKey
{
    public function __construct(
        private readonly TransactionGuard $transactions,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $projectId): void
    {
        $this->transactions->assertInTransaction('Locking a project key');

        Project::query()->whereKey($projectId)->whereNull('key_locked_at')->update(['key_locked_at' => $this->clock->now()]);
    }
}
