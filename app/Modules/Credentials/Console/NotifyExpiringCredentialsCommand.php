<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Console;

use App\Modules\Credentials\Actions\NotifyExpiringCredentials;
use App\Modules\Credentials\Queries\DueCredentials;
use Illuminate\Console\Command;

final class NotifyExpiringCredentialsCommand extends Command
{
    protected $signature = 'credentials:notify-expiring';

    protected $description = 'Announce credentials that expire within a week';

    public function handle(DueCredentials $due, NotifyExpiringCredentials $notify): int
    {
        $count = 0;
        $afterId = null;

        do {
            $rows = $due->expiringWithin(NotifyExpiringCredentials::DAYS_AHEAD, afterId: $afterId);

            foreach ($rows as $row) {
                $count += $notify->handle($row['organizationId'], $row['credentialId']);
                $afterId = $row['credentialId'];
            }
        } while ($rows !== []);

        $this->info("Announced {$count} expiring credentials.");

        return self::SUCCESS;
    }
}
