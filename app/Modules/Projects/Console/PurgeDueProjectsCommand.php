<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Projects\Actions\PurgeProject;
use App\Modules\Projects\Queries\DueDeletions;
use Illuminate\Console\Command;

final class PurgeDueProjectsCommand extends Command
{
    protected $signature = 'projects:purge-due';

    protected $description = 'Delete projects whose grace period has ended';

    public function handle(DueDeletions $due, PurgeProject $purge): int
    {
        $purged = 0;

        foreach ($due->projects() as $row) {
            $purged += $purge->handle($row['organizationId'], $row['projectId']) ? 1 : 0;
        }

        $this->info("Purged {$purged} projects.");

        return self::SUCCESS;
    }
}
