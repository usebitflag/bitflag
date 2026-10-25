<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Console;

use App\Modules\Organizations\Actions\ExpireInvitations;
use Illuminate\Console\Command;

final class ExpireInvitationsCommand extends Command
{
    protected $signature = 'invitations:expire';

    protected $description = 'Mark pending invitations whose seven days are up as expired';

    public function handle(ExpireInvitations $expire): int
    {
        $this->line('expired '.$expire->handle());

        return self::SUCCESS;
    }
}
