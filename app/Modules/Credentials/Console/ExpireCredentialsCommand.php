<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Console;

use App\Modules\Credentials\Actions\ExpireCredential;
use App\Modules\Credentials\Queries\DueCredentials;
use Illuminate\Console\Command;

final class ExpireCredentialsCommand extends Command
{
    protected $signature = 'credentials:expire';

    protected $description = 'Mark credentials past their expiry as expired';

    public function handle(DueCredentials $due, ExpireCredential $expire): int
    {
        $rows = $due->toExpire();

        foreach ($rows as $row) {
            $expire->handle($row['organizationId'], $row['credentialId']);
        }

        $this->info('Expired '.count($rows).' credentials.');

        return self::SUCCESS;
    }
}
