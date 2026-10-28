<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Console;

use App\Modules\Credentials\Queries\PepperUsage;
use Illuminate\Console\Command;

final class PepperUsageCommand extends Command
{
    protected $signature = 'credentials:pepper-usage';

    protected $description = 'Show how many working credentials use each credential pepper';

    public function handle(PepperUsage $usage): int
    {
        $counts = $usage->counts();

        if ($counts === []) {
            $this->info('No working credentials.');

            return self::SUCCESS;
        }

        $this->table(['Pepper', 'Working credentials'], array_map(static fn (int $pepper, int $count): array => [$pepper, $count], array_keys($counts), $counts));

        return self::SUCCESS;
    }
}
