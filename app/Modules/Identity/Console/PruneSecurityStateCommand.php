<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\PruneSecurityState;
use Illuminate\Console\Command;

final class PruneSecurityStateCommand extends Command
{
    protected $signature = 'identity:prune';

    protected $description = 'Delete old security events, expired sessions and trusted browsers, and stale two-factor enrollments';

    public function handle(PruneSecurityState $prune): int
    {
        foreach ($prune->handle() as $kind => $count) {
            $this->line(str_pad(str_replace('_', ' ', $kind), 18).$count);
        }

        return self::SUCCESS;
    }
}
