<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Console;

use App\Modules\Organizations\Actions\PurgeOrganization;
use App\Modules\Organizations\Queries\DueOrganizationDeletions;
use Illuminate\Console\Command;

final class PurgeDueOrganizationsCommand extends Command
{
    protected $signature = 'organizations:purge-due';

    protected $description = 'Delete organizations whose grace period has ended';

    public function handle(DueOrganizationDeletions $due, PurgeOrganization $purge): int
    {
        $purged = 0;

        foreach ($due->ids() as $organizationId) {
            $purged += $purge->handle($organizationId) ? 1 : 0;
        }

        $this->info("Purged {$purged} organizations.");

        return self::SUCCESS;
    }
}
