<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Projects\Actions\ExpireProjectExport;
use App\Modules\Projects\Queries\ProjectExports;
use Illuminate\Console\Command;

final class ExpireProjectExportsCommand extends Command
{
    protected $signature = 'projects:expire-exports';

    protected $description = 'Delete the files of project exports older than 24 hours';

    public function handle(ProjectExports $exports, ExpireProjectExport $expire): int
    {
        $due = $exports->due();

        foreach ($due as $row) {
            $expire->handle($row['organizationId'], $row['exportId']);
        }

        $this->info('Expired '.count($due).' exports.');

        return self::SUCCESS;
    }
}
