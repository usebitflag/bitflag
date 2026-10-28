<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Console;

use App\Modules\Credentials\Actions\CompleteRotation;
use App\Modules\Credentials\Queries\DueCredentials;
use Illuminate\Console\Command;

final class CompleteCredentialRotationsCommand extends Command
{
    protected $signature = 'credentials:complete-rotations';

    protected $description = 'Revoke old keys whose rotation overlap has ended';

    public function handle(DueCredentials $due, CompleteRotation $complete): int
    {
        $rows = $due->rotationsToComplete();

        foreach ($rows as $row) {
            $complete->handle($row['organizationId'], $row['credentialId']);
        }

        $this->info('Completed '.count($rows).' rotations.');

        return self::SUCCESS;
    }
}
