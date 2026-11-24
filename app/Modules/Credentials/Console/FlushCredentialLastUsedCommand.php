<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Console;

use App\Modules\Credentials\Actions\FlushCredentialLastUsed;
use Illuminate\Console\Command;

final class FlushCredentialLastUsedCommand extends Command
{
    protected $signature = 'credentials:flush-last-used';

    protected $description = 'Copy the credential last-use times recorded in Redis into the database';

    public function handle(FlushCredentialLastUsed $flush): int
    {
        $this->info("Updated {$flush->handle()} credentials.");

        return self::SUCCESS;
    }
}
